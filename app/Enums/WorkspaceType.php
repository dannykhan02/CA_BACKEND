<?php

namespace App\Enums;

enum WorkspaceType: string
{
    case Personal = 'Personal';
    case Organization = 'Organization';
}