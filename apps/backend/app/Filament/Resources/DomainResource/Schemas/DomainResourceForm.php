<?php

declare(strict_types=1);

namespace App\Filament\Resources\DomainResource\Schemas;

use Filament\Schemas\Schema;

class DomainResourceForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([]);
    }
}
