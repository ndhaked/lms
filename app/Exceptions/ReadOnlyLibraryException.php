<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

class ReadOnlyLibraryException extends HttpException
{
    public function __construct()
    {
        parent::__construct(403, 'This library is read-only because its subscription has expired. You can view your data, but nothing can be changed until it is renewed.');
    }
}
