<?php

declare(strict_types=1);

namespace App\Enums;

enum PageType: string
{
    case Home = 'home';
    case Product = 'product';
    case Collection = 'collection';
    case Cart = 'cart';
    case Checkout = 'checkout';
    case Blog = 'blog';
    case Contact = 'contact';
    case Unknown = 'unknown';
}
