<?php

declare(strict_types = 1);

namespace Tests\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\Repositories\Attributes\Model;
use SineMacula\Repositories\Exceptions\RepositoryException;
use SineMacula\Repositories\Repository;
use Tests\Support\Models\Tag;
use Tests\Support\Models\TagAlias;
use Tests\Support\Models\TestUser;
use Tests\Support\Repositories\AttributedBaseRepository;
use Tests\Support\Repositories\AttributedTagRepository;
use Tests\Support\Repositories\InheritedAttributeTagRepository;
use Tests\Support\Repositories\OverridingAttributedRepository;
use Tests\Support\Repositories\PlainTestUserRepository;
use Tests\Support\Repositories\UndeclaredModelRepository;

/**
 * Tests for declaring a repository's model with an attribute.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(Repository::class)]
#[CoversClass(Model::class)]
final class ModelAttributeTest extends IntegrationTestCase
{
    /**
     * Verify a repository declaring its model with the attribute resolves and
     * queries it, with no model() implementation of its own.
     *
     * @return void
     *
     * @throws \Throwable
     */
    public function testAttributeDeclaresTheModel(): void
    {
        Tag::create(['name' => 'php']);

        $repository = $this->repository(AttributedTagRepository::class);

        self::assertSame(Tag::class, $repository->model());
        self::assertInstanceOf(Tag::class, $repository->getModel());
        self::assertCount(1, $repository->query()->get());
    }

    /**
     * Verify a declaration on an ancestor reaches its subclasses, since class
     * attributes are not inherited and the lookup has to walk for them.
     *
     * @return void
     *
     * @throws \Throwable
     */
    public function testADeclarationOnAnAncestorReachesItsSubclasses(): void
    {
        self::assertSame(Tag::class, $this->repository(InheritedAttributeTagRepository::class)->model());
    }

    /**
     * Verify an override replaces the lookup, so the nearer declaration wins
     * exactly as an inherited override would.
     *
     * @return void
     *
     * @throws \Throwable
     */
    public function testAnOverrideReplacesAnInheritedDeclaration(): void
    {
        self::assertSame(TagAlias::class, $this->repository(OverridingAttributedRepository::class)->model());
    }

    /**
     * Verify a repository implementing model() is untouched by the lookup.
     *
     * @return void
     *
     * @throws \Throwable
     */
    public function testAnImplementedModelMethodStillWins(): void
    {
        self::assertSame(TestUser::class, $this->repository(PlainTestUserRepository::class)->model());
    }

    /**
     * Verify a repository declaring neither fails at construction, naming both
     * ways of declaring one.
     *
     * @return void
     *
     * @throws \Throwable
     */
    public function testDeclaringNeitherFailsAtConstruction(): void
    {
        $this->expectException(RepositoryException::class);
        $this->expectExceptionMessage('must declare its model');

        $this->repository(UndeclaredModelRepository::class);
    }

    /**
     * Verify a generated double resolves the model of the class it stands in
     * for: such a double copies the attribute by name, dropping its arguments.
     *
     * @return void
     *
     * @throws \Throwable
     */
    public function testAGeneratedDoubleResolvesTheDeclaredModel(): void
    {
        $double = \Mockery::mock(AttributedBaseRepository::class)->makePartial();

        // The generated class carries a copy of the attribute with no
        // arguments, so resolving has to walk past it to the real declaration.
        self::assertNotSame([], (new \ReflectionClass($double))->getAttributes(Model::class));
        self::assertSame([], (new \ReflectionClass($double))->getAttributes(Model::class)[0]->getArguments());
        self::assertSame(Tag::class, $double->model());
    }

    /**
     * Resolve a repository from the container.
     *
     * @param  class-string<\SineMacula\Repositories\Repository<\Illuminate\Database\Eloquent\Model>>  $repository
     * @return \SineMacula\Repositories\Repository<\Illuminate\Database\Eloquent\Model>
     *
     * @throws \Throwable
     */
    private function repository(string $repository): Repository
    {
        self::assertNotNull($this->app);

        return $this->app->make($repository);
    }
}
