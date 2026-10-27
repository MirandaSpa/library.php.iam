<?php

declare(strict_types=1);

namespace Miranda\Iam;

use Miranda\Iam\Exception\ManifestException;
use Miranda\Iam\Exception\RegistrationRejectedException;
use Miranda\Iam\Exception\RegistrationUnavailableException;

/**
 * Console-friendly facade to register a manifest when a container starts. It
 * has no Symfony dependency: wrap it in your own console command and use
 * exitCode() and message().
 *
 *   exit 0  registered
 *   exit 1  nothing to retry: wrong usage, unreadable or invalid manifest, or iam rejected it
 *   exit 2  iam unavailable after every attempt
 */
final class IamBootstrap
{
    public const EXIT_OK = 0;
    public const EXIT_REJECTED = 1;
    public const EXIT_UNAVAILABLE = 2;

    public const USAGE = 'usage: <manifest.yaml> <iam-url>   (for example /app/permissions.yaml https://iam.example.test/api/v2/iam)';

    /** @var ManifestRegistrar */
    private $registrar;
    /** @var int */
    private $maxAttempts;
    /** @var int */
    private $delaySeconds;
    /** @var callable|null */
    private $sleep;

    /**
     * @param callable|null $sleep receives the seconds to wait; defaults to sleep()
     */
    public function __construct(?ManifestRegistrar $registrar = null, int $maxAttempts = 10, int $delaySeconds = 3, ?callable $sleep = null)
    {
        $this->registrar = $registrar ?? new ManifestRegistrar();
        $this->maxAttempts = $maxAttempts;
        $this->delaySeconds = $delaySeconds;
        $this->sleep = $sleep;
    }

    /**
     * @param string[] $args the arguments after the command name: manifest path and iam URL
     */
    public function run(array $args): BootstrapResult
    {
        $args = array_values($args);
        if (count($args) !== 2 || !is_string($args[0]) || !is_string($args[1]) || $args[0] === '' || $args[1] === '') {
            return new BootstrapResult(self::EXIT_REJECTED, self::USAGE);
        }

        return $this->register($args[0], $args[1]);
    }

    public function register(string $manifestPath, string $iamUrl): BootstrapResult
    {
        try {
            $manifest = ManifestLoader::fromFile($manifestPath);
            $result = $this->registrar->registerWithRetry($manifest, $iamUrl, $this->maxAttempts, $this->delaySeconds, $this->sleep);
        } catch (ManifestException $e) {
            return new BootstrapResult(self::EXIT_REJECTED, $e->getMessage());
        } catch (RegistrationRejectedException $e) {
            return new BootstrapResult(self::EXIT_REJECTED, $e->getMessage());
        } catch (RegistrationUnavailableException $e) {
            return new BootstrapResult(self::EXIT_UNAVAILABLE, $e->getMessage());
        } catch (\InvalidArgumentException $e) {
            return new BootstrapResult(self::EXIT_REJECTED, $e->getMessage());
        }

        return new BootstrapResult(
            self::EXIT_OK,
            sprintf('manifest of %s registered in iam (%d permissions, %d routes)', $manifest->service(), $result->permissions(), $result->routes())
        );
    }
}
