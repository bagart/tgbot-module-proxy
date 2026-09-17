<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Models;

enum FeedFormat: string
{
    case TextLine = 'text_line';
    case JsonArray = 'json_array';
    case ClashYaml = 'clash_yaml';
    case SingboxJson = 'singbox_json';
    case V2rayJson = 'v2ray_json';
}
