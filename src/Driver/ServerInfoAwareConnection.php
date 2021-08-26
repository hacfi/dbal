<?php

declare(strict_types=1);

namespace Doctrine\DBAL\Driver;

/**
 * Contract for a connection that is able to provide information about the server it is connected to.
 *
 * @deprecated The methods defined in this interface will be made part of the {@link Driver} interface
 * in the next major release.
 */
interface ServerInfoAwareConnection extends Connection
{
    /**
     * Returns the version number of the database server connected to.
     *
     * @throws Exception
     */
    public function getServerVersion(): string;
}
