# library.php.iam

Cliente PHP de `iam`, el servicio de permisos de Repuestos Miranda. Es el equivalente en PHP de la librería Go `library.go.iam` y lo usa el backend legacy (PHP 7.4 y 8) y cualquier proceso PHP futuro (consumers, comandos, cron).

Hace cuatro cosas:

1. **Carga y valida el manifiesto** (`permissions.yaml`) con las mismas reglas que `iam` y la librería Go.
2. **Registra el manifiesto en `iam`** al arrancar, con reintentos.
3. **Pide el token de una cuenta de servicio** al proveedor de identidad y lo mantiene en memoria.
4. **Pregunta a `iam`** si una persona o una petición tiene permiso (`authz` y `check`).

Es un repositorio público a propósito: no contiene secretos, hosts ni datos de Miranda. Los ejemplos usan `https://iam.example.test`.

> **Registrar un manifiesto no da permisos a nadie.** Solo agrega permisos y rutas al catálogo de `iam`. Los permisos se asignan después con roles y asignaciones (a personas, grupos o cuentas de servicio). Las rutas que registra un servicio reemplazan las que tenía.

## Requisitos

- PHP `^7.4 || ^8.0`, con `ext-curl` y `ext-json`.
- `symfony/yaml` `^4.4 || ^5.0 || ^6.0` (única dependencia de ejecución).
- Sin librería HTTP: la librería define una interfaz mínima, `HttpTransport`, y trae `CurlTransport` (ext-curl) como implementación por defecto. Si quieres usar Guzzle u otro cliente, implementa `HttpTransport` (un solo método, `send(HttpRequest): HttpResponse`).

## Instalación desde GitHub

Todavía no está en Packagist: se instala desde el repositorio con un `repositories` de tipo `vcs` en el `composer.json` del proyecto.

```json
{
    "repositories": [
        { "type": "vcs", "url": "https://github.com/MirandaSpa/library.php.iam" }
    ],
    "require": {
        "mirandaspa/library-php-iam": "^0.1"
    }
}
```

Fija siempre un tag (`vX.Y.Z`); para probar una rama usa `dev-nombre-de-la-rama`.

## Uso

En todos los ejemplos, `$iamUrl` es la base de la API de `iam` **sin** `/internal`, por ejemplo `https://iam.example.test/api/v2/iam`.

### 1. Cargar un manifiesto

```php
use Miranda\Iam\ManifestLoader;
use Miranda\Iam\Exception\ManifestException;

try {
    $manifest = ManifestLoader::fromFile(__DIR__ . '/permissions.yaml'); // o ::fromArray([...])
} catch (ManifestException $e) {
    foreach ($e->problems() as $problem) {   // todos los problemas, no solo el primero
        fwrite(STDERR, $problem . PHP_EOL);
    }
    exit(1);
}
```

Un test de CI del proyecto que llame a `ManifestLoader::fromFile` ya valida el manifiesto sin tocar la red.

### 2. Registrarlo con reintentos

```php
use Miranda\Iam\ManifestRegistrar;
use Miranda\Iam\Exception\RegistrationRejectedException;
use Miranda\Iam\Exception\RegistrationUnavailableException;

$registrar = new ManifestRegistrar();               // timeout de 5 s por intento
try {
    // hasta 10 intentos, 3 s entre uno y otro; solo reintenta si iam no responde
    $result = $registrar->registerWithRetry($manifest, $iamUrl, 10, 3);
    echo $result->permissions() . ' permisos, ' . $result->routes() . ' rutas' . PHP_EOL;
} catch (RegistrationRejectedException $e) {
    // 400, 409 o 422: iam rechazó el manifiesto; no tiene sentido reintentar
} catch (RegistrationUnavailableException $e) {
    // red, timeout o 5xx después de todos los intentos
}
```

`register()` hace un solo intento. Los errores `400`, `409` y `422` son rechazos; red, timeout, 5xx y cualquier otra respuesta son "no disponible". El mensaje de un rechazo trae solo lo que `iam` dice (ids de permisos, nombres de servicios), nunca credenciales.

Para registrar al arrancar un contenedor hay una fachada sin dependencias de Symfony; envuélvela en tu propio comando de consola:

```php
use Miranda\Iam\IamBootstrap;

$result = (new IamBootstrap())->run([$argv[1], $argv[2]]); // ruta del manifiesto y URL de iam
fwrite(STDERR, $result->message() . PHP_EOL);
exit($result->exitCode());   // 0 registrado, 1 rechazado o inválido, 2 iam no disponible
```

### 3. Token de una cuenta de servicio

```php
use Miranda\Iam\Credentials;
use Miranda\Iam\ServiceAccountTokenProvider;

// Variables: IAM_SERVICE_USERNAME, IAM_SERVICE_CREDENTIAL, IAM_CLIENT_ID, IAM_TOKEN_URL
$credentials = Credentials::fromEnv(getenv());
// o desde un archivo JSON {"username": "...", "credential": "..."} con permisos 600:
// $credentials = Credentials::fromFile('/run/secrets/iam.json', 'client-id', 'https://idp.example.test/token');

$tokens = new ServiceAccountTokenProvider($credentials);
$jwt = $tokens->token();   // se guarda en memoria y se renueva cuando le quedan menos de 60 s
// Authorization: Bearer <jwt>
```

Hace `client_credentials` contra el proveedor de identidad (`grant_type`, `client_id`, `username`, `password`, `scope`). Si `iam` responde 401 con un token vigente, llama a `$tokens->invalidate()` y pide otro.

### 4. Preguntar a `iam`

```php
use Miranda\Iam\AuthzClient;

$authz = new AuthzClient($iamUrl);   // timeout de 500 ms, sin reintentos

// Una petición HTTP completa (equivale al auth-url del Ingress)
$answer = $authz->authorize('GET', 'https://legacy.example.test/api/empaque/voucher/7?x=1', $authorizationHeader, $branchId);
if ($answer->isAllowed()) {
    $userId = $answer->userId();           // X-Usuario-Id, tomado del token
} elseif ($answer->isUnauthenticated()) {  // 401
} elseif ($answer->isForbidden()) {        // 403
} else {                                   // unavailable: iam no respondió o respondió un error
}

// Un usuario conocido (por ejemplo, un consumer que actúa por el usuario de un evento)
$allowed = $authz->check($userId, 'storage:packing:view', $branchId);
```

`authorize()` nunca lanza excepciones por fallas de `iam` y nunca reintenta: devuelve `unavailable` y quien llama decide (el middleware del legacy responde 503, o deja pasar en modo `shadow`). `check()` devuelve `bool` y, si no puede preguntar, lanza `AuthzUnavailableException`: no responde `false`, porque no poder preguntar no es lo mismo que denegar.

## Reglas del manifiesto

Son las mismas que aplica `iam` y la librería Go. Si algo falla, `ManifestException` los lista todos.

- `service` es obligatorio: minúsculas, dígitos, `-` y `_` (hasta 60). Va en la URL, no en el cuerpo.
- Cada permiso tiene un `id` de exactamente 3 segmentos `area:resource:action`, cada uno con `[a-z0-9_-]+` (hasta 120 caracteres en total), sin repetirse.
- `label` es obligatorio y de hasta 255 caracteres; `description`, de hasta 500.
- `scope`: `branch` (por defecto) o `global`. `audit`: `denied` (por defecto) o `always`. `kind`: `entry` (por defecto) o `internal`.
- Cada ruta tiene `method` en mayúsculas (`GET`, `POST`, `PUT`, `PATCH` o `DELETE`) y un `path` que empieza con `/`, sin espacios, `?` ni `#`, sin segmentos vacíos y de hasta 255 caracteres. Los parámetros se escriben `{name}` con letras, dígitos, `_` y `-`, y ocupan el segmento completo.
- Cada ruta declara **una de dos**: `permission` (que el manifiesto debe declarar) o `public: true`.
- No puede haber dos rutas con el mismo método y path. Se comparan por forma: `/a/{id}` y `/a/{otro}` son la misma ruta, y la barra final no cuenta.

```yaml
service: store
permissions:
  - id: store:reception:create
    label: Crear preingreso
    description: Permite registrar un preingreso en la sucursal.
    scope: branch
    audit: denied
    kind: entry
routes:
  - method: POST
    path: /api/v2/storage/reception
    permission: store:reception:create
  - method: GET
    path: /api/v2/storage/health
    public: true
```

## Seguridad

- **La credencial nunca se registra.** `Credentials` guarda el secreto dentro de una clausura y no lo muestra en `var_dump`, `print_r`, `var_export`, `json_encode`, conversión a texto ni `serialize()` (siempre sale `[REDACTED]`). Los mensajes de error de la librería nunca la incluyen. Lee `credential()` solo para enviarla al proveedor de identidad.
- **Archivo de credenciales:** en sistemas POSIX se rechaza si el grupo u otros pueden leerlo (`chmod 600`). Guarda la credencial donde se guarda cualquier secreto (Sealed Secret, secret de Docker); nunca en el código ni en el repositorio.
- **Scope del token:** el valor por defecto es `openid profile`. Sin `profile` el token no trae `preferred_username` y `iam` responde 401 porque no sabe de qué cuenta es.
- **La query string nunca se envía.** `authorize()` corta la URL en el primer `?` o `#` antes de ponerla en `X-Original-URL`: puede traer tokens o datos personales y `iam` solo necesita el path.
- `Authorization`, `X-Original-Method`, `X-Original-URL` y `X-Sucursal-Id` son las cabeceras que `iam` lee. Una cabecera con salto de línea no se envía (el resultado es `unavailable`).
- `CurlTransport` verifica TLS, no sigue redirecciones, solo habla http(s) y corta respuestas de más de 1 MiB.
- La URL de `iam` debe ser http(s) absoluta, sin usuario ni contraseña, query ni fragmento.
- Las rutas `/internal` de `iam` no se publican fuera del cluster (salvo `/internal/authz`); ten en cuenta desde dónde corre cada proceso.

## Desarrollo

```bash
composer install
vendor/bin/phpunit
```

Las pruebas no usan red (hay un transporte falso que registra las peticiones; solo `CurlTransportTest` abre un servidor local en `127.0.0.1`). Para medir cobertura hace falta `pcov` o `xdebug`:

```bash
vendor/bin/phpunit --coverage-text
```

La CI corre las pruebas en PHP 7.4 y 8.2 y exige al menos 70 % de cobertura. El código debe seguir compilando en PHP 7.4: sin `enum`, `readonly`, tipos unión, promoción de propiedades en el constructor, `match`, argumentos con nombre ni `str_contains`.
