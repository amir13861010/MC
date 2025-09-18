<?php

namespace App\Traits;

use Illuminate\Database\QueryException;
use Illuminate\Database\ConnectionException;
use PDOException;

trait HandlesDatabaseErrors
{
    /**
     * Execute a database operation with error handling
     */
    protected function handleDatabaseOperation(callable $operation, $fallback = null)
    {
        try {
            return $operation();
        } catch (QueryException $e) {
            if ($this->isDatabaseConnectionError($e)) {
                return $this->handleConnectionError();
            }
            throw $e;
        } catch (ConnectionException $e) {
            return $this->handleConnectionError();
        } catch (PDOException $e) {
            if ($this->isDatabaseConnectionError($e)) {
                return $this->handleConnectionError();
            }
            throw $e;
        }
    }

    /**
     * Handle database connection errors
     */
    protected function handleConnectionError()
    {
        return response()->json([
            'message' => 'OMEGA, Updating assets'
        ], 503);
    }

    /**
     * Check if the exception is a database connection error
     */
    private function isDatabaseConnectionError(\Exception $e): bool
    {
        $connectionErrorMessages = [
            'Connection refused',
            'SQLSTATE[HY000]',
            'could not connect to server',
            'No connection could be made',
            'Connection timed out',
            'Connection reset by peer',
            'Lost connection to MySQL server',
            'MySQL server has gone away',
            'Can\'t connect to MySQL server',
            'SQLSTATE[08006]',
            'SQLSTATE[08001]',
            'SQLSTATE[08003]',
            'SQLSTATE[08004]',
            'SQLSTATE[08007]'
        ];

        $message = $e->getMessage();
        
        foreach ($connectionErrorMessages as $errorMessage) {
            if (str_contains($message, $errorMessage)) {
                return true;
            }
        }

        return false;
    }
}
