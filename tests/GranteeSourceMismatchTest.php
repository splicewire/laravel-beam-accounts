<?php

namespace Splicewire\Beam\Accounts\Tests;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use PDOException;
use Splicewire\Beam\Accounts\Entitlements\Contracts\GranteeSource;
use Splicewire\Beam\Accounts\Entitlements\DefaultEntitlementResolver;
use Splicewire\Beam\Accounts\Entitlements\GranteeSourceMismatch;
use Splicewire\Beam\Accounts\Localization\AccountError;

class GranteeSourceMismatchTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        MismatchGrantModel::$sqlState = '22P02';
        MismatchGrantModel::$seenBindings = [];
    }

    public function test_a_grantee_key_rejected_by_the_grant_columns_throws_a_coded_source_mismatch(): void
    {
        $this->configureMismatchSource();

        try {
            $this->app->make(DefaultEntitlementResolver::class)->entitlementsFor(new \stdClass);
            $this->fail('The resolver must surface a grantee key rejected by the host grant model.');
        } catch (GranteeSourceMismatch $exception) {
            $this->assertSame(AccountError::GranteeKeyMismatch, $exception->error);
            $this->assertSame('grantee_key_mismatch', $exception->error->code());
            $this->assertStringContainsString(MismatchGranteeSource::class, $exception->getMessage());
            $this->assertStringContainsString(MismatchGrantModel::class, $exception->getMessage());
            $this->assertStringContainsString('string', $exception->getMessage());
            $this->assertContains('tenant-slug', MismatchGrantModel::$seenBindings);
            $this->assertInstanceOf(QueryException::class, $exception->getPrevious());
            $this->assertSame('22P02', $exception->getPrevious()->errorInfo[0]);
        }
    }

    public function test_a_non_data_query_failure_is_rethrown_without_grantee_mismatch(): void
    {
        $this->configureMismatchSource();
        MismatchGrantModel::$sqlState = '08006';

        try {
            $this->app->make(DefaultEntitlementResolver::class)->entitlementsFor(new \stdClass);
            $this->fail('The simulated connection failure should reach the caller.');
        } catch (QueryException $exception) {
            $this->assertSame('08006', $exception->errorInfo[0]);
        }
    }

    private function configureMismatchSource(): void
    {
        $this->app['config']->set('permission-cascade.grant_model', MismatchGrantModel::class);
        $this->app['config']->set('beam.accounts.entitlements.grantees', MismatchGranteeSource::class);
    }
}

class MismatchGranteeSource implements GranteeSource
{
    public function granteesFor(mixed $principal): iterable
    {
        return [(new StringKeyGrantee)->forceFill(['id' => 'tenant-slug'])];
    }
}

class StringKeyGrantee extends Model
{
    protected $table = 'grantees';

    protected $keyType = 'string';

    public $incrementing = false;
}

class MismatchGrantModel extends Model
{
    public static string $sqlState = '22P02';

    public static array $seenBindings = [];

    protected $table = 'access_grants';

    public function newEloquentBuilder($query): Builder
    {
        return new class($query) extends Builder
        {
            public function pluck($column, $key = null)
            {
                $bindings = $this->getQuery()->getBindings();
                MismatchGrantModel::$seenBindings = $bindings;

                $previous = new PDOException('simulated typed grantee-column refusal');
                $previous->errorInfo = [MismatchGrantModel::$sqlState, 22, $previous->getMessage()];

                throw new QueryException('testing', $this->toSql(), $bindings, $previous);
            }
        };
    }
}
