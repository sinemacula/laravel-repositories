<?php

declare(strict_types = 1);

namespace Tests\Unit;

use Illuminate\Container\Container;
use Illuminate\Contracts\Foundation\Application;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\Repositories\Exceptions\RepositoryException;
use SineMacula\Repositories\Repository;
use Tests\Support\Models\TestUser;
use Tests\Support\Repositories\TestUserRepository;

/**
 * Unit tests for non-database repository behavior.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(Repository::class)]
#[CoversClass(RepositoryException::class)]
class RepositoryTest extends TestCase
{
    /**
     * Ensure invalid model resolutions fail fast.
     *
     * @return void
     *
     * @throws \PHPUnit\Framework\MockObject\Exception
     */
    public function testMakeModelThrowsWhenResolvedClassIsNotEloquentModel(): void
    {
        $app = $this->createMock(Application::class);
        $app->expects(static::exactly(2))
            ->method('make')
            ->with(TestUser::class)
            ->willReturnOnConsecutiveCalls(new TestUser, new \stdClass);

        $repository = new TestUserRepository($app);

        $this->expectException(RepositoryException::class);
        $this->expectExceptionMessage('must be an instance of Illuminate\Database\Eloquent\Model');

        $repository->makeModel();
    }

    /**
     * Ensure static calls fail when no Laravel application container is active.
     *
     * @return void
     */
    public function testStaticCallsRequireInitializedLaravelApplicationContainer(): void
    {
        $original_container = Container::getInstance();

        try {
            Container::setInstance(new Container);

            $this->expectException(RepositoryException::class);
            $this->expectExceptionMessage('Static repository calls require an initialized Laravel container');

            TestUserRepository::count();
        } finally {
            Container::setInstance($original_container);
        }
    }
}
