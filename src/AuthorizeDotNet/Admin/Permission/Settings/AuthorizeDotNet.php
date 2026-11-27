<?php

namespace Nails\Invoice\Driver\Payment\AuthorizeDotNet\Admin\Permission\Settings;

use Nails\Admin\Interfaces\Permission;

class AuthorizeDotNet implements Permission
{
    public function label(): string
    {
        return 'Can manage Authorize.NET settings';
    }

    public function group(): string
    {
        return 'Settings';
    }
}
