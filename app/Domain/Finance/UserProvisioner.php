<?php

namespace App\Domain\Finance;

use App\Domain\Ledger\AccountService;
use App\Enums\AccountSubtype;
use App\Enums\CategoryKind;
use App\Enums\EntityType;
use App\Models\Category;
use App\Models\LedgerAccount;
use App\Models\Merchant;
use App\Models\User;
use App\Models\UserAlias;
use App\Models\UserSetting;
use App\Support\Text;
use Illuminate\Support\Facades\DB;

/**
 * Creates a user and copies the default catalog for them. Safe to run repeatedly.
 */
class UserProvisioner
{
    public function __construct(private readonly AccountService $accounts) {}

    public function provision(string $waId, ?string $name = null): User
    {
        return DB::transaction(function () use ($waId, $name) {
            $user = User::findByWaId($waId);

            if (! $user) {
                $user = new User([
                    'name' => $name,
                    'timezone' => config('moneytalks.defaults.timezone'),
                    'base_currency' => config('moneytalks.defaults.currency'),
                    'locale' => config('moneytalks.defaults.locale'),
                    'status' => 'active',
                ]);
                $user->setWaId($waId);
                $user->save();
            } elseif ($name !== null && $user->name === null) {
                $user->update(['name' => $name]);
            }

            UserSetting::firstOrCreate(['user_id' => $user->id]);
            $this->seedCatalog($user);
            $this->seedAccounts($user);

            return $user;
        });
    }

    /** System ledger accounts plus a Cash account (so a first expense works immediately). */
    public function seedAccounts(User $user): void
    {
        $this->accounts->ensureSystemAccounts($user);

        $cash = LedgerAccount::where('user_id', $user->id)->where('name', 'Cash')->first()
            ?? $this->accounts->create($user, 'Cash', AccountSubtype::Cash);

        $this->seedAliases($user, EntityType::Account, $cash->id, ['cash', 'cash in hand', 'नकद', 'nakad']);
        $user->settings()->whereNull('default_account_id')->update(['default_account_id' => $cash->id]);
    }

    public function seedCatalog(User $user): void
    {
        $sort = 0;
        foreach (DefaultCatalog::categories() as $kind => $roots) {
            foreach ($roots as $name => $node) {
                $this->seedCategory($user, CategoryKind::from($kind), $name, $node, null, $sort++);
            }
        }

        foreach (DefaultCatalog::merchants() as $name => $def) {
            $category = Category::where('user_id', $user->id)->where('name', $def['default_category'])->first();
            $merchant = Merchant::firstOrCreate(
                ['user_id' => $user->id, 'name' => $name],
                ['default_category_id' => $category?->id],
            );
            $this->seedAliases($user, EntityType::Merchant, $merchant->id, $def['aliases']);
        }
    }

    private function seedCategory(User $user, CategoryKind $kind, string $name, array $node, ?Category $parent, int $sort): void
    {
        $path = $parent ? $parent->path.' > '.$name : $name;

        $category = Category::firstOrCreate(
            ['user_id' => $user->id, 'kind' => $kind->value, 'path' => $path],
            ['name' => $name, 'parent_id' => $parent?->id, 'sort' => $sort],
        );

        $this->seedAliases($user, EntityType::Category, $category->id, $node['aliases'] ?? []);

        $i = 0;
        foreach ($node['children'] ?? [] as $childName => $child) {
            $this->seedCategory($user, $kind, $childName, $child, $category, $i++);
        }
    }

    /** @param string[] $aliases */
    private function seedAliases(User $user, EntityType $type, string $entityId, array $aliases): void
    {
        foreach ($aliases as $alias) {
            UserAlias::firstOrCreate(
                ['user_id' => $user->id, 'entity_type' => $type->value, 'alias' => Text::normalize($alias)],
                ['entity_id' => $entityId, 'source' => 'seed'],
            );
        }
    }
}
