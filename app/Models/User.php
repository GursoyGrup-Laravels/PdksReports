<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\BooleanStatusEnum;
use App\Enums\ManagerStatusEnum;
use App\Enums\UserTypeEnum;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Permission\Traits\HasRoles;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use App\Enums\ActiveStatusEnum;
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;
    use SoftDeletes;
    use HasRoles;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'employee_id',
        'name',
        'email',
        'phone',
        'status',
        'is_manager',
        'password',
        'project_id',
        'project_name',
        'created_by',
        'updated_by',
        'deleted_by',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'status' => ManagerStatusEnum::class,
            'is_manager' => BooleanStatusEnum::class,
        ];
    }

    // Relation with managers
    public function managers()
    {
        return $this->hasOne(Manager::class, 'employee_id', 'employee_id');
    }

    // Relation with employee
    public function employee()
    {
        return $this->belongsTo(Employee::class, 'employee_id', 'id');
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->status->is(ManagerStatusEnum::ACTIVE);
    }

    /**
     * Kimse kendi izinlerinden fazlasını veya super_admin rolünü veremez.
     */
    public function canGrantRole(\Spatie\Permission\Models\Role $role): bool
    {
        if ($this->hasRole('super_admin')) {
            return true;
        }

        return $role->name !== 'super_admin'
            && $role->permissions->pluck('id')->diff($this->getAllPermissions()->pluck('id'))->isEmpty();
    }

    /**
     * @return list<int>
     */
    public function grantableRoleIds(): array
    {
        return \Spatie\Permission\Models\Role::with('permissions')->get()
            ->filter(fn ($role): bool => $this->canGrantRole($role))
            ->pluck('id')
            ->values()
            ->all();
    }

    public function canManageUser(User $target): bool
    {
        return $target->roles->every(fn ($role): bool => $this->canGrantRole($role))
            && ($this->hasRole('super_admin')
                || $target->permissions->pluck('id')->diff($this->getAllPermissions()->pluck('id'))->isEmpty());
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function deletedBy()
    {
        return $this->belongsTo(User::class, 'deleted_by');
    }

//    public static function query()
//    {
//        $hasPermission = auth()->user()->hasRole('super_admin') || auth()->user()->can('view_all_users');
//
//        if ($hasPermission) {
//            return parent::query();
//        } else {
//            return parent::query()->where('created_by', auth()->id());
//        }
//    }
}
