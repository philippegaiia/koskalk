<?php

namespace App;

enum WorkspaceMemberRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Editor = 'editor';
    case Viewer = 'viewer';
}
