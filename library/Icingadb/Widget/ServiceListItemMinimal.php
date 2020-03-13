<?php

/* Icinga DB Web | (c) 2020 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Icingadb\Widget;

use Icinga\Module\Icingadb\Common\ListItemMinimalLayout;
use ipl\Web\Widget\StateBall;

class ServiceListItemMinimal extends BaseServiceListItem
{
    use ListItemMinimalLayout;

    protected function getStateBallSize()
    {
        return StateBall::SIZE_BIG;
    }
}
