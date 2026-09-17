<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Bot\Wizard;

enum WizardType: string
{
    case Import = 'import';
    case Export = 'export';
    case List = 'list';
    case Check = 'check';
    case Settings = 'settings';
}
