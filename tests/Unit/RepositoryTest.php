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
     * Test that the Model attribute declares the model without a model() body.
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
     * Test that a declaration on an ancestor reaches its subclasses.
     *
     * Class attributes are not inherited, so the lookup walks the hierarchy.
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
     * Test that a model() override replaces the lookup.
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
     * Test that a repository declaring neither fails with an actionable error.
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
     * Test that a declaration without arguments is skipped.
     *
     * A generated double copies its parent's attributes, minus their arguments.
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
     * Test that the resolved declaration is memoised.
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

        (new \ReflectionProperty(Repository::class, 'declaredModel'))
            ->setValue($repository, TagAlias::class);

        self::assertSame(TagAlias::class, $repository->model());
    }

    /**
     * Test that makeModel fails when the container resolves a non-model.
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
     * Test that the constructor boots concerns with a dedicated boot hook.
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
     * Test that static calls fail when no application container is active.
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
     * @param  class-string<\SineMacula\Repositories\Repository<covariant \Illuminate\Database\Eloquent\Model>>  $repository
     * @return \SineMacula\Repositories\Repository<covariant \Illuminate\Database\Eloquent\Model>
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
