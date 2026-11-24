# RedEnlace / ATC — QR SIMPLE V2

**Guía técnica de integración y aplicación a PrestaShop 1.7.8.2 (Petsofia)**  
**Revisión:** 17 de noviembre de 2026  
**Fuente principal:** [Documentación oficial QR SIMPLE](https://openhub.redenlace.com.bo/es/products/apis-de-cobro-mediante-qr/cobro-por-qr-simple)  
**Referencia complementaria:** [Vista API / Swagger](https://openhub.redenlace.com.bo/es/products/apis-de-cobro-mediante-qr/cobro-por-qr-simple/swagger) (la vista se identificó en resultados públicos, pero su contenido completo no pudo recuperarse en esta revisión).

> **Alcance y trazabilidad.** Este documento sintetiza los apartados 1–8 de la documentación pública oficial de QR SIMPLE V2, con ejemplos redactados y sin reproducir secretos. Las secciones marcadas **[OFICIAL]** describen el contrato publicado; **[RECOMENDACIÓN]** son decisiones de arquitectura para el módulo PrestaShop; **[POR CONFIRMAR]** no están resueltas en la documentación examinada. Esta guía no certifica que se haya invocado la API ni sustituye las pruebas con RedEnlace.

## 1. Resumen funcional [OFICIAL]

La integración comprende autenticación OAuth 2.0 Client Credentials, generación de un QR, consulta de su estado y notificación de pago mediante webhook. La documentación también describe la cancelación de QRs vigentes.

| Operación | Método | Ruta |
|---|---|---|
| Obtener access token | POST | `/oauth-client-credentials/access-token?grant_type=client_credentials` |
| Generar QR | POST | `/qr/simple/v2/generate` |
| Consultar estado | GET | `/qr/simple/v2/verify/{numeroReferencia}` |
| Cancelar QR pendiente | POST | `/qr/simple/v2/cancel/{numeroReferencia}` |
| Notificación a integrador | POST | URL de webhook declarada en la generación |

## 2. Ambientes y credenciales [OFICIAL]

| Ambiente | URL base publicada | Acceso |
|---|---|---|
| Certificación / Sandbox | `https://atcgwapitest.redenlace.com.bo/sandbox/` | Credenciales ATC para pruebas |
| Producción | `https://api.redenlace.com.bo/` | Credenciales ATC restringidas |

Las credenciales de Sandbox no son credenciales productivas. Para obtener credenciales de producción, RedEnlace exige coordinar las pruebas Sandbox, obtener aprobación de su equipo de integraciones y completar el checklist que proporciona ese equipo. Solo después se puede solicitar el acceso productivo.

**[RECOMENDACIÓN]** Guardar la URL base por ambiente, `client_id`, secreto OAuth, identificador/nombre de establecimiento y configuración del webhook en Back Office. Nunca incrustar secretos en plantillas del Front Office ni en JavaScript. No confundir la URL descriptiva del portal OpenHub con la URL operativa del gateway Sandbox.

## 3. Autenticación OAuth 2.0 [OFICIAL]

**Solicitud**

```http
POST /oauth-client-credentials/access-token?grant_type=client_credentials
Authorization: Basic <BASE64(client_id:client_secret)>
Content-Type: application/x-www-form-urlencoded
```

- Flujo: `client_credentials`.
- `Authorization` usa Basic con el par `client_id:client_secret` codificado en Base64.
- El parámetro `grant_type` vale `client_credentials`.
- La documentación define `Content-Type: application/x-www-form-urlencoded`.

**Ejemplo esquemático de respuesta:**

```json
{
  "access_token": "<ACCESS_TOKEN>",
  "token_type": "Bearer",
  "expires_in": 3600,
  "scope": "api.qr"
}
```

| Campo | Tipo | Significado |
|---|---|---|
| `access_token` | string | Token para llamadas posteriores |
| `token_type` | string | `Bearer` |
| `expires_in` | integer | Vigencia en segundos |
| `scope` | string | Alcance autorizado |

**Importante:** pese a que la respuesta indica `token_type: Bearer`, la documentación de los endpoints QR muestra un header **`access_token`**, no `Authorization: Bearer ...`. Implementar los encabezados documentados para QR y validar con el gateway; no asumir que ambas formas son equivalentes.

**[RECOMENDACIÓN]** Obtener el token automáticamente desde el backend, almacenarlo de forma temporal con margen de expiración y renovarlo cuando sea necesario. Las credenciales almacenadas una sola vez en Back Office se reutilizan; no hay que pedir nuevamente el Client Secret al comprador ni al administrador cuando venza el token. Evitar exponer el token o el secreto en logs.

## 4. Generación del QR [OFICIAL]

**Endpoint:** `POST /qr/simple/v2/generate`

**Headers:**

```http
access_token: <ACCESS_TOKEN_OAUTH>
client_id: <CLIENT_ID_ATC>
Content-Type: application/json
```

**Request de ejemplo** (valores ilustrativos, no credenciales habilitadas):

```json
{
  "glosa": "Pago de pedido 4024",
  "moneda": "BOB",
  "monto": 10.5,
  "numeroReferencia": "4024",
  "vigencia": 45,
  "idEstablecimiento": 123456,
  "nombreEstablecimiento": "ESTABLECIMIENTO_DE_PRUEBA",
  "webhook": {
    "url": "https://ejemplo.com/qr/confirmed",
    "key": "x-api-key",
    "value": "<SECRETO_WEBHOOK>"
  }
}
```

**Nota:** `123456` y el nombre son *marcadores ilustrativos*. ATC exige un identificador de establecimiento registrado; no se ha verificado que admita valores ficticios en Sandbox.

| Campo | Tipo publicado | Requerido | Descripción |
|---|---|---|---|
| `glosa` | string | Sí | Concepto del cobro |
| `moneda` | string | Sí | Código permitido: `BOB` |
| `monto` | decimal | Sí | Importe, máximo dos decimales |
| `numeroReferencia` | string | Sí | Identificador originante asignado por el integrador |
| `vigencia` | integer | Sí | Vigencia del QR **en segundos**; ejemplo oficial `45` |
| `idEstablecimiento` | integer | Sí | ID del establecimiento registrado en ATC |
| `nombreEstablecimiento` | string | Sí | Nombre del establecimiento habilitado |
| `webhook.url` | string | Sí | Endpoint de confirmación de pago |
| `webhook.key` | string | Sí | Nombre de la cabecera para autenticar webhook |
| `webhook.value` | string | Sí | Valor del header de autenticación |

**Ejemplo de respuesta exitosa:**

```json
{
  "success": true,
  "message": "QR generado exitosamente",
  "data": {
    "numeroReferencia": "153980",
    "estado": "PENDIENTE",
    "fechaExpiracion": "2026-03-12T18:01:52.304302",
    "moneda": "BOB",
    "monto": 10.5,
    "numeroReferenciaOriginante": "4024",
    "qr": "iVBORw0KGgoAAAANSUhEUg..."
  }
}
```

| Campo respuesta | Significado |
|---|---|
| `success`, `message` | Resultado y descripción |
| `data.numeroReferencia` | **Referencia ATC** para `verify` y `cancel` |
| `data.numeroReferenciaOriginante` | Referencia enviada por el sistema integrador |
| `data.estado` | Inicialmente `PENDIENTE` |
| `data.fechaExpiracion` | Fecha/hora de expiración publicada como cadena ISO 8601 |
| `data.moneda`, `data.monto` | Valores de la transacción |
| `data.qr` | Imagen **PNG codificada en Base64** |

**Distinción crítica:** conservar por separado `numeroReferenciaOriginante` (nuestro pedido/transacción) y `numeroReferencia` (ATC). Los endpoints de consulta y cancelación utilizan **el segundo**.

**[RECOMENDACIÓN]** Decodificar/validar la imagen PNG en el servidor o entregar al frontend solo el Base64 validado (no los headers de autenticación). Confirmar formatos, tamaño máximo y expiración real del QR con casos de prueba.

## 5. Consulta del estado [OFICIAL]

**Endpoint:** `GET /qr/simple/v2/verify/{numeroReferencia}`

Usa la referencia **interna ATC** retornada por la generación.

```http
GET /qr/simple/v2/verify/153980
access_token: <ACCESS_TOKEN_OAUTH>
client_id: <CLIENT_ID_ATC>
Content-Type: application/json
```

**Ejemplo de respuesta `PENDIENTE`:**

```json
{
  "success": true,
  "message": "Estado de QR consultado exitosamente",
  "data": {
    "estado": "PENDIENTE",
    "mensaje": "QR generado, esperando pago",
    "importe": 10.5,
    "moneda": "BOB",
    "numeroReferenciaOriginante": "4024",
    "numeroReferencia": "153980",
    "clienteOrigen": {
      "nombreCliente": "",
      "numeroCuenta": "",
      "ciNitCliente": ""
    },
    "bancoOrigen": {
      "numeroOrdenAch": "",
      "codigoBanco": "",
      "nombreBanco": "",
      "fechaTransaccion": ""
    }
  }
}
```

Los datos publicados contemplan:

- `estado`, `mensaje`, `importe`, `moneda`, `numeroReferenciaOriginante`, `numeroReferencia`.
- `clienteOrigen`: `nombreCliente`, `numeroCuenta`, `ciNitCliente`.
- `bancoOrigen`: `numeroOrdenAch`, `codigoBanco`, `nombreBanco`, `fechaTransaccion` (documentada como fecha ISO 8601).

**Ejemplo de error:**

```json
{
  "success": false,
  "message": "Error al consultar estado de QR",
  "errors": [
    {
      "field": "numeroReferencia",
      "message": "Transacción no encontrada con número de referencia: 153980",
      "code": "TRANSACCION_NO_ENCONTRADA"
    }
  ]
}
```

## 6. Webhook de confirmación [OFICIAL, CON INCONSISTENCIA]

Al confirmarse el pago, ATC emite un **HTTP POST** a la URL configurada en la generación, con `Content-Type: application/json`. El integrador debe responder **HTTP 200** para confirmar recepción. El sitio dice que el payload tiene la misma estructura que la respuesta de consulta del estado, **pero su ejemplo concreto muestra un esquema diferente**.

**Ejemplo de webhook mostrado en la documentación oficial:**

```json
{
  "detalleRespuesta": "Transacción procesada correctamente",
  "codigoRespuesta": "SUCCESS",
  "numeroReferencia": "233324",
  "monto": 10.5,
  "fechaHoraTransaccion": "2026-05-26T14:35:20",
  "moneda": "BOB",
  "clienteOrigen": {
    "ciCliente": "12345678",
    "nombreCliente": "Juan Perez",
    "numeroCuenta": "1234567890"
  },
  "bancoOrigen": {
    "codigoBanco": "101",
    "nombreBanco": "Banco Unión",
    "numeroOrdenAch": "987654321"
  }
}
```

**Inconsistencias que deben aclararse con RedEnlace:**

1. El texto dice que el webhook replica la respuesta de `verify`, pero el ejemplo usa `codigoRespuesta: SUCCESS`, `detalleRespuesta`, `monto` y `fechaHoraTransaccion` en lugar de `success`, `data.estado`, `data.importe`, etc.
2. En `clienteOrigen`, el ejemplo de webhook usa `ciCliente`; el de `verify` usa `ciNitCliente`.
3. La sección de webhook menciona `webhookUrl`, aunque la generación requiere el objeto `webhook.url`.
4. No se documentan de forma suficientemente precisa el mecanismo de simulación de pagos Sandbox, firma criptográfica del webhook, política de reintentos, rangos IP de origen ni tiempos máximos de respuesta.

**[RECOMENDACIÓN]** No dar por pagado un pedido solo porque alguien envió un POST al callback. Verificar la cabecera `webhook.key/value`, correlacionar referencia, monto y moneda y corroborar el estado `PAGADO` mediante `verify` antes de cambiar el estado del pedido. Implementar idempotencia, comparación resistente a timing para secretos, registro no sensible, protección ante replays y respuesta 200 cuando la notificación válida ya fue procesada. Antes de fijar el parser final, solicitar a ATC muestras reales de ambos tipos de evento en Sandbox.

## 7. Cancelación [OFICIAL]

**Endpoint:** `POST /qr/simple/v2/cancel/{numeroReferencia}`

Se cancela usando **la referencia ATC**. Según la documentación, se pueden cancelar QRs `PENDIENTE`, pero no QRs ya pagados o cancelados.

```http
POST /qr/simple/v2/cancel/153980
access_token: <ACCESS_TOKEN_OAUTH>
client_id: <CLIENT_ID_ATC>
Content-Type: application/json
```

**Respuesta exitosa:**

```json
{
  "success": true,
  "message": "Transacción cancelada",
  "data": {
    "estado": "CANCELADO",
    "mensaje": "Transacción cancelada",
    "importe": 10.5,
    "moneda": "BOB",
    "numeroReferenciaOriginante": "4024",
    "numeroReferencia": "153980"
  }
}
```

**Error al intentar cancelar un QR ya cancelado o pagado:** la API informa `success: false` con el código `ESTADO_INVALIDO` en `errors[].code`.

## 8. Estados del QR [OFICIAL]

| Estado | Significado | Implicación para la tienda |
|---|---|---|
| `PENDIENTE` | QR creado, sin pago confirmado | Mantener el pedido en `Pendiente de pago QR RedEnlace`. |
| `PAGADO` | Pago confirmado | Tras `verify`, transicionar el pedido una sola vez a `PS_OS_PAYMENT`. |
| `CANCELADO` | QR cancelado antes de pago | Registrar la cancelación; no cobrar ni finalizar el pedido. |
| `EXPIRADO` | Transcurrió el plazo sin pago | Registrar expiración y permitir un nuevo intento QR en una fase posterior. |
| `ERROR` | Error de procesamiento | Registrar para diagnóstico; no marcar pagado. |

El documento distingue entre estados de consulta y los resultados de generación (inicialmente `PENDIENTE` o `ERROR`).

## 9. Modelo de integración para Petsofia / PrestaShop [RECOMENDACIÓN]

**Contexto utilizado:** PrestaShop 1.7.8.2, PHP 7.4 para desarrollo/compatibilidad, módulo `modules/redenlace`, configuración desde Back Office, checkout pendiente de habilitar.

### 9.1. Arquitectura propuesta

```text
Comprador (Front Office)
  -> Checkout / opción RedEnlace
  -> Controlador servidor PrestaShop
       -> configuración persistida (BO)
       -> servicio OAuth: obtener/reutilizar access_token
       -> QR Client: POST /generate
       -> persistir referencia originante + referencia ATC
  <- página de pago con imagen QR (sin secretos)
  -> consulta de estado propia (backend -> GET /verify)

ATC/RedEnlace
  -> POST webhook a controlador público HTTPS de PrestaShop
       -> validar secreto y payload
       -> verificar transacción con GET /verify
       -> comprobar referencia, moneda e importe
       -> guardar el estado QR
       -> si y solo si es PAGADO, actualizar el pedido de manera idempotente
```

### 9.2. Separación Back Office / Front Office

**Back Office:** configuración del ambiente, credenciales OAuth cifradas, establecimiento, vigencia del QR y prueba de conectividad OAuth. El secreto del webhook pertenece al entorno seguro de despliegue, no al formulario administrativo.

**Front Office:** elección del medio de pago, creación de la transacción, renderizado del QR, estados `PENDIENTE`/`PAGADO`/`EXPIRADO`/`CANCELADO`, confirmación del pedido, sin exponer secretos.

**Backend:** acceso centralizado a la configuración guardada, renovación automática del token, solicitudes a ATC, persistencia, verificación y webhook.

### Configuración de despliegue del webhook

El Back Office solicita únicamente la URL pública HTTPS del webhook. El módulo genera una vez un secreto aleatorio de 256 bits, lo cifra mediante `RedEnlaceSecretStorage` en la configuración de PrestaShop y nunca lo muestra ni lo incorpora en plantillas o JavaScript. Para cada `/generate`, envía dicho valor usando el nombre de cabecera fijo `X-RedEnlace-Webhook-Token` dentro de `webhook.key/value`; ATC debe devolver exactamente ese header al llamar al endpoint.

El secreto no se deriva de la URL: una URL es pública y predecible, por lo que no aporta autenticación. Al rotar manualmente el secreto en una futura función administrativa, deben mantenerse o conciliarse los QRs aún pendientes que fueron creados con el valor anterior. La URL debe apuntar a un webhook implementado y validado antes de activar pagos para clientes.

### 9.3. Componentes sugeridos

| Componente | Responsabilidad |
|---|---|
| `RedEnlaceOAuthClient` | Autenticación, caché y renovación de token |
| `RedEnlaceQRClient` | Generar, verificar y cancelar QRs |
| `RedEnlaceTransactionRepository` | Guardar correlación de pedidos y transacciones |
| `RedEnlaceOrderSynchronizer` | Transicionar el pedido a pago aceptado solo tras `verify=PAGADO` |
| Controlador de pago Front Office | Iniciar checkout y mostrar QR |
| Controlador webhook | Recibir y verificar notificaciones |
| Servicio de conciliación | Consultar estados pendientes ante webhook perdido |
| Configuración del módulo | Credenciales, establecimiento, ambiente y parámetros |

Los nombres son sugerencias, **no** clases exigidas por la API ni una afirmación de que ya existan en el repositorio.

### 9.4. Datos locales mínimos

Tabla transaccional sugerida (prefijo dinámico PrestaShop):

| Campo | Propósito |
|---|---|
| ID local de transacción | Clave primaria |
| ID de carrito / pedido | Correlación con PrestaShop |
| Referencia originante | Referencia enviada al generar QR |
| Referencia ATC | Identificador retornado por ATC |
| Monto y moneda | Conciliación y detección de discrepancias |
| Estado ATC | Estado observado / verificado |
| Fecha expiración | Control del QR vigente |
| Fecha creación / actualización | Auditoría |
| Fecha de sincronización de pedido | Recuperar pagos verificados cuyo cambio de estado PrestaShop no terminó |
| ID evento o huella de notificación | Deduplicación, si existe ID documentalmente disponible; si no, clave idempotente interna |

Usar índices únicos donde corresponda; no almacenar tokens ni el secreto webhook en esta tabla.

### 9.5. Idempotencia y errores

- Prevenir doble creación de pagos al recargar checkout o reintentar solicitudes.
- No suponer que una llamada fallida implica ausencia de transacción remota: primero conciliar.
- Aplicar timeout HTTP, manejo de respuestas mal formadas y registro de códigos sin valores sensibles.
- No declarar un pedido `pagado` desde el navegador; solo tras verificación servidor a servidor.
- Tratar los montos como decimales con dos posiciones; evitar comparaciones ingenuas con flotantes.
- Comprobar la semántica de `vigencia` (documentada en segundos) y no confundirla con la expiración de OAuth (`expires_in`).

## 10. Secuencia de implementación recomendada [RECOMENDACIÓN]

- [x] Fase 1: OAuth Client Credentials en Sandbox (según las pruebas previamente descritas del proyecto; **no** revalidado mediante este estudio documental).
- [ ] Fase 2A: confirmar `idEstablecimiento` y `nombreEstablecimiento` para Sandbox.
- [ ] Fase 2B: integrar generación de QR y validar estructura `data.qr` PNG Base64.
- [ ] Fase 2C: comprobar `verify` y `cancel` con referencia ATC.
- [ ] Fase 3A: registrar método de pago y mostrar QR desde checkout real.
- [ ] Fase 3B: procesar webhooks con autenticación, reconciliación e idempotencia.
- [ ] Fase 3C: reflejar correctamente estados en pedidos PrestaShop.
- [ ] Fase 4: logs saneados, recuperación ante fallos, pruebas negativas, checklist ATC y habilitación de producción.

## 11. Matriz mínima de pruebas [RECOMENDACIÓN]

| Prueba | Validación |
|---|---|
| OAuth válido | Recibir token con expiración |
| OAuth vencido | Renovación automática sin intervención BO |
| Generar QR | Recibir `success=true`, referencia ATC y PNG Base64 válido |
| Referencia diferenciada | No usar la originante para `verify`/`cancel` |
| Consultar pendiente | Obtener `PENDIENTE` |
| Cancelar pendiente | Obtener `CANCELADO` |
| Cancelar pagado/cancelado | Rechazo por `ESTADO_INVALIDO` |
| Referencia inexistente | Manejar `TRANSACCION_NO_ENCONTRADA` |
| Pago confirmado | Validar identidad, importe, moneda y `PAGADO` |
| Webhook duplicado | No duplicar abonos ni cambios de estado |
| Webhook inválido | Rechazar autenticación/correlación incorrecta |
| QR expirado | Evitar marcar pagado sin confirmación y ofrecer reintento |
| Checkout real | Comprador completa flujo desde carrito hasta confirmación |

## 12. Preguntas pendientes para ATC / RedEnlace [POR CONFIRMAR]

1. ¿Qué `idEstablecimiento` (integer) y `nombreEstablecimiento` habilitados debemos utilizar en Sandbox? ¿Se pueden usar datos ficticios o existe establecimiento de pruebas suministrado por ATC?
2. ¿Cómo simular un pago exitoso, pendiente, vencido, cancelado y error en Sandbox para probar el webhook?
3. ¿Cuál es el **contrato definitivo** del webhook: estructura anidada equivalente a `verify` o estructura con `codigoRespuesta: SUCCESS`? ¿Qué header envía exactamente `webhook.key/value`?
4. ¿El webhook incorpora firma o timestamp; cuál es el procedimiento oficial de autenticación, política de reintentos y deduplicación?
5. ¿Existe un intervalo mínimo de consulta o rate limit para `verify`? ¿Cuáles son los errores HTTP y códigos posibles de `generate`?
6. ¿Cuáles son los límites efectivos de `vigencia` y `monto` y las reglas de unicidad/reutilización de `numeroReferencia`?
7. ¿La URL de webhook debe estar registrada/autorizada previamente? ¿Se requiere IP pública fija o lista de allowlist?
8. ¿Cuáles son los criterios específicos del checklist de certificación antes de recibir credenciales de producción?

## 13. Alertas de precisión documental

- El ejemplo de la documentación contiene `idEstablecimiento: xxxxxx`, que **no es JSON válido**. Para una solicitud real se requiere un entero habilitado.
- La sección de webhook **contradice su propio ejemplo de payload**. No resolver por intuición.
- La sección de ejemplos titula una consulta de estado como “pago exitoso” aunque el JSON mostrado tiene estado `PENDIENTE`. Se debe usar `estado` y no el título para inferir el estado real.
- El gateway de producción publicado es distinto del de Sandbox; no reutilizar las llaves entre ambientes.
- La vista Swagger accesible por buscador lista `generate`, `verify` y `cancel`, pero la recuperación de su contenido completo falló; las especificaciones detalladas de este archivo se apoyan en la **documentación técnica HTML oficial**, no en un OpenAPI descargado.

## 14. Fuentes oficiales

1. [RedEnlace OpenHub — Inicio](https://openhub.redenlace.com.bo/es)
2. [RedEnlace OpenHub — Cobro por QR SIMPLE, especificación completa](https://openhub.redenlace.com.bo/es/products/apis-de-cobro-mediante-qr/cobro-por-qr-simple)
3. [RedEnlace OpenHub — Vista Swagger de QR SIMPLE](https://openhub.redenlace.com.bo/es/products/apis-de-cobro-mediante-qr/cobro-por-qr-simple/swagger) — referencia complementaria no recuperada íntegramente.

---

**Criterio de aceptación para avanzar a checkout:** generar QR con valores habilitados en Sandbox, almacenar correctamente las dos referencias, verificar estados con la referencia ATC y contar con un contrato confirmado para procesar notificaciones de pago sin acreditar transacciones falsas o duplicadas.
