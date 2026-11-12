# Petsofia

Repositorio de desarrollos a medida para Petsofia. Actualmente contiene el
módulo de pago RedEnlace para PrestaShop; en el futuro puede incorporar otros
proyectos del cliente sin mezclar su documentación ni sus paquetes de entrega.

## Módulo PrestaShop: RedEnlace QR

Módulo de pago QR para PrestaShop 1.7.8.2 que integra los servicios de
RedEnlace. El código del módulo se encuentra en `modules/redenlace/`.

### Estado actual

Está implementada la Fase 1: configuración administrativa y comprobación de
autenticación OAuth para el entorno Sandbox. El módulo todavía no participa en
el checkout ni crea códigos QR, pedidos o transacciones.

Funcionalidad disponible:

- Instalación del módulo sin registrar hooks de pago incompletos.
- Página **Configurar** en el Back Office de PrestaShop.
- Configuración de URL TEST/Sandbox, Client ID y Client Secret.
- Botón **Probar conexión Sandbox**.
- Resultado limitado a estado de autenticación, tipo de token y vigencia.
- Compatibilidad objetivo: PrestaShop 1.7.8.2 y PHP 7.4.

### Seguridad

- Las credenciales nunca se incluyen en el código, HTML de respuesta, logs ni
  paquetes de despliegue.
- El `Client Secret` se cifra y autentica antes de almacenarse en
  `Configuration`; no se asume que PrestaShop cifre esos valores.
- La clave de cifrado se deriva de la clave privada de la instalación
  PrestaShop (`_COOKIE_KEY_`).
- Las acciones de guardar y probar requieren un empleado autenticado con
  permiso de edición sobre `AdminModules` y un token CSRF válido.
- La conexión OAuth usa HTTPS y validación de certificados TLS.

### Alcance pendiente

Las siguientes funcionalidades no están implementadas y no deben asumirse:

- Método de pago visible durante el checkout.
- Generación y presentación de códigos QR.
- Persistencia de transacciones.
- Callbacks o webhooks.
- Verificación de pagos y actualización de estados de pedido.

Antes de implementar cualquiera de ellas se debe revisar
`atcapiqrsimplev2.yaml`, que es la referencia técnica del API RedEnlace incluida
en este repositorio.

### Estructura relevante

```text
modules/redenlace/
├── redenlace.php                         # Módulo y configuración Back Office
├── classes/
│   ├── RedEnlaceOAuthClient.php           # Cliente OAuth reutilizable
│   └── RedEnlaceSecretStorage.php         # Cifrado de secretos almacenados
└── index.php

docker/php74/Dockerfile                    # Entorno local PHP 7.4
tests/test-oauth.php                       # Prueba local, no se despliega
artifacts/redenlace/                       # ZIP locales versionados, ignorados
```

### Configuración de Sandbox

1. Instale el módulo desde el administrador de módulos de PrestaShop.
2. Abra **Módulos → RedEnlace QR → Configurar**.
3. Registre la URL TEST/Sandbox, Client ID y Client Secret provistos por
   RedEnlace.
4. Guarde los datos.
5. Seleccione **Probar conexión Sandbox**.

Una autenticación correcta muestra únicamente el tipo de token y su vigencia.
No se muestra el valor del token ni el secreto.

### Desarrollo local

El archivo `.env` es exclusivamente local y no se versiona. Use
`.env.example` como referencia y defina como mínimo:

```dotenv
REDENLACE_BASE_URL=https://<sandbox-host>
REDENLACE_CLIENT_ID=<client-id>
REDENLACE_CLIENT_SECRET=<client-secret>
```

Construya la imagen PHP 7.4:

```sh
docker build -t petsofia-php74 -f docker/php74/Dockerfile .
```

Valide la sintaxis del módulo y ejecute la prueba OAuth local:

```sh
docker run --rm -v "$PWD:/app:ro" -w /app petsofia-php74 \
  sh -lc "find modules/redenlace tests -type f -name '*.php' -exec php -l {} \\;"

docker run --rm -v "$PWD:/app:ro" -w /app petsofia-php74 \
  php tests/test-oauth.php
```

La prueba de OAuth no imprime credenciales ni el `access_token`.

### Paquetes de despliegue

El despliegue actual se realiza mediante el administrador de módulos de
PrestaShop o transferencia FTP autorizada. El paquete debe contener solo la
carpeta `redenlace/` y no incluir `.env`, pruebas, logs ni secretos.

Genere paquetes locales con nombre de versión en `artifacts/redenlace/`:

```sh
cd modules
zip -r ../artifacts/redenlace/redenlace-0.1.0.zip redenlace
```

Los archivos ZIP de esa carpeta están ignorados intencionalmente por Git.

### Control de cambios

Las operaciones Git y GitHub son responsabilidad del propietario del
repositorio. No se deben incluir secretos, paquetes ZIP, artefactos locales ni
archivos `.env` en el control de versiones.
