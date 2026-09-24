<?php

declare(strict_types = 1);

namespace Tests\Unit;

use Illuminate\Container\Container;
use Illuminate\Contracts\Foundation\Application;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\Repositories\Attributes\Model;
use SineMacula\Repositories\Exceptions\RepositoryException;
use SineMacula\Repositories\Repository;
use Tests\Support\Models\Tag;
use Tests\Support\Models\TagAlias;
use Tests\Support\Models\TestUser;
use Tests\Support\Repositories\AttributedBaseRepository;
use Tests\Support\Repositories\AttributedTagRepository;
use Tests\Support\Repositories\BootableConcernRepository;
use Tests\Support\Repositories\InheritedAttributeTagRepository;
use Tests\Support\Repositories\OverridingAttributedRepository;
use Tests\Support\Repositories\TestUserRepository;
use Tests\Support\Repositories\UndeclaredModelRepository;

/**
 * Unit tests for non-database repository behavior.
 *
 * @SuppressWarnings("php:S3011")
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(Repository::class)]
#[CoversClass(Model::class)]
#[CoversClass(RepositoryException::class)]
final class RepositoryTest extends TestCase
{
    /**
     * Ensure the Model attribute declares the model without a model() body.
     *
     * @return void
     *
     * @throws \SineMacula\Repositories\Exceptions\RepositoryException
     */
    public function testTheAttributeDeclaresTheModel(): void
    {
        self::assertSame(Tag::class, $this->attributed(AttributedTagRepository::class)->model());
    }

    /**
     * Ensure a declaration on an ancestor reaches its subclasses, since class
     * attributes are not inherited and the lookup has to walk for them.
     *
     * @return void
     *
     * @throws \PHPUnit\Framework\MockObject\Exception
     * @throws \SineMacula\Repositories\Exceptions\RepositoryException
     */
    public function testADeclarationOnAnAncestorReachesItsSubclasses(): void
    {
        self::assertSame(Tag::class, $this->attributed(InheritedAttributeTagRepository::class)->model());
    }

    /**
     * Ensure an override replaces the lookup entirely.
     *
     * @return void
     *
     * @throws \PHPUnit\Framework\MockObject\Exception
     * @throws \SineMacula\Repositories\Exceptions\RepositoryException
     */
    public function testAnOverrideReplacesTheLookup(): void
    {
        $app = self::createStub(Application::class);
        $app->method('make')->willReturn(new TagAlias);

        $repository = new OverridingAttributedRepository($app);

        self::assertSame(TagAlias::class, $repository->model());
    }

    /**
     * Ensure a repository declaring neither fails, naming both ways to declare
     * a model so the message is actionable.
     *
     * @return void
     *
     * @throws \PHPUnit\Framework\MockObject\Exception
     * @throws \SineMacula\Repositories\Exceptions\RepositoryException
     */
    public function testDeclaringNeitherFails(): void
    {
        $this->expectException(RepositoryException::class);
        $this->expectExceptionMessage('must declare its model with the `' . Model::class . '` attribute or by overriding model()');

        $this->attributed(UndeclaredModelRepository::class);
    }

    /**
     * Ensure a declaration carrying no arguments is walked past rather than
     * read: a generated double copies the attributes of the class it stands in
     * for by name alone.
     *
     * @return void
     *
     * @throws \PHPUnit\Framework\MockObject\Exception
     * @throws \SineMacula\Repositories\Exceptions\RepositoryException
     */
    public function testADeclarationWithoutArgumentsIsSkipped(): void
    {
        $double = \Mockery::mock(AttributedBaseRepository::class)->makePartial();

        self::assertSame([], (new \ReflectionClass($double))->getAttributes(Model::class)[0]->getArguments());
        self::assertSame(Tag::class, $double->model());
    }

    /**
     * Ensure the resolved declaration is memoised, so the per-query model
     * rebuild does not reflect on every call.
     *
     * @return void
     *
     * @throws \PHPUnit\Framework\MockObject\Exception
     * @throws \SineMacula\Repositories\Exceptions\RepositoryException
     */
    public function testTheResolvedDeclarationIsMemoised(): void
    {
        $repository = $this->attributed(AttributedTagRepository::class);

        self::assertSame(Tag::class, $repository->model());

        // Once resolved the memo is authoritative: a second call reads it back
        // rather than reflecting over the hierarchy again.
        (new \ReflectionProperty(Repository::class, 'declaredModel'))
            ->setValue($repository, TagAlias::class);

        self::assertSame(TagAlias::class, $repository->model());
    }

    /**
     * Ensure invalid model resolutions fail fast.
     *
     * @return void
     *
     * @throws \PHPUnit\Framework\MockObject\Exception
     * @throws \SineMacula\Repositories\Exceptions\RepositoryException
     */
    public function testMakeModelThrowsWhenResolvedClassIsNotEloquentModel(): void
    {
        $app = $this->createMock(Application::class);
        $app->expects(self::exactly(2))
            ->method('make')
            ->with(TestUser::class)
            ->willReturnOnConsecutiveCalls(new TestUser, new \stdClass);

        $repository = new TestUserRepository($app);

        $this->expectException(RepositoryException::class);
        $this->expectExceptionMessage('must be an instance of Illuminate\Database\Eloquent\Model');

        $repository->makeModel();
    }

    /**
     * Ensure the constructor boots every used concern that exposes a dedicated
     * boot hook, after the subclass boot() has run.
     *
     * @return void
     *
     * @throws \PHPUnit\Framework\MockObject\Exception
     * @throws \SineMacula\Repositories\Exceptions\RepositoryException
     */
    public function testConstructorBootsConcernsWithDedicatedBootHooks(): void
    {
        $app = $this->createMock(Application::class);
        $app->method('make')
            ->with(TestUser::class)
            ->willReturn(new TestUser);

        $repository = new BootableConcernRepository($app);

        self::assertTrue($repository->hasBootedConcern());
    }

    /**
     * Ensure static calls fail when no Laravel application container is active.
     *
     * @return void
     */
    public function testStaticCallsRequireInitializedLaravelApplicationContainer(): void
    {
        $originalContainer = Container::getInstance();

        try {

            Container::setInstance(new Container);

            $this->expectException(RepositoryException::class);
            $this->expectExceptionMessage('Static repository calls require an initialized Laravel container');

            TestUserRepository::count();
        } finally {
            Container::setInstance($originalContainer);
        }
    }

    /**
     * Build an attribute-declared repository against a mocked container.
     *
     * @param  class-string<\SineMacula\Repositories\Repository<\Illuminate\Database\Eloquent\Model>>  $repository
     * @return \SineMacula\Repositories\Repository<\Illuminate\Database\Eloquent\Model>
     *
     * @throws \SineMacula\Repositories\Exceptions\RepositoryException
     */
    private function attributed(string $repository): Repository
    {
        $app = self::createStub(Application::class);
        $app->method('make')->willReturn(new Tag);

        return new $repository($app);
    }
}
