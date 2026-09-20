<?php

namespace App\Ai\Tools\Dashboard;

use App\Ai\Tools\AssistantTool;
use App\Interfaces\Dashboard\DashboardServiceInterface;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;

/**
 * Common ground of the tools that wrap `DashboardServiceInterface`.
 *
 * Every one forwards the authenticated user, so the scope rules of SPEC 29 apply
 * untouched: a `carrier` only ever reads its own company and `carrierId` is a
 * voluntary filter for `administrator`/`manager`.
 */
abstract class DashboardTool extends AssistantTool
{
    public function __construct(
        User $user,
        protected readonly DashboardServiceInterface $dashboard,
    ) {
        parent::__construct($user);
    }

    /**
     * Schema of the `carrierId` argument, shared by the dashboard tools.
     */
    protected function carrierIdSchema(JsonSchema $schema): Type
    {
        return $schema->integer()
            ->description('Id de la empresa transportista. Solo lo aplica un administrator o manager; para un carrier se ignora y siempre lee su propia empresa. Un id inexistente se ignora.');
    }
}
