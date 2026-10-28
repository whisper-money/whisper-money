# Autenticación

Cada petición lleva un token bearer: un token personal que creas en Ajustes, o un token de acceso OAuth que recibe una app cuando la apruebas.

{{TOC}}

## Tokens bearer

Envía el token en la cabecera `Authorization` de cada petición:

```text
Authorization: Bearer wm_api_tu_token
```

La API no lee nada más. No usa cookies ni tu sesión iniciada, así que tener
Whisper Money abierto en el mismo navegador no basta: una petición sin token
responde `401`.

Hay dos tipos de token. Los dos llegan a los mismos endpoints.

|              | Token personal                          | Token OAuth                         |
| ------------ | --------------------------------------- | ----------------------------------- |
| Para         | Tus propios scripts                     | Apps hechas por otros               |
| Se crea en   | **Ajustes → API**                       | La app, después de que la apruebes  |
| Permisos     | Solo lectura, o lectura y escritura     | Lectura y escritura                 |
| Duración     | 30 días, 90 días, 1 año o sin caducidad | La app lo renueva                   |
| Se revoca en | **Ajustes → API → Tokens personales**   | **Ajustes → API → Apps conectadas** |

## Tokens personales

Créalo en **Ajustes → API → Tokens personales**. Ponle un nombre que vayas a
reconocer más adelante y elige:

- **Permiso.** Los tokens de _solo lectura_ pueden leer tus datos. Los de
  _lectura y escritura_ también pueden crearlos, editarlos y borrarlos.
- **Caducidad.** 30 días, 90 días (por defecto), 1 año o sin caducidad. Un token
  caducado responde `401`; crea otro, o rótalo antes de que caduque.

El token se muestra una sola vez, al crearlo. Empieza por `wm_api_`, y así es
fácil reconocerlo si acaba donde no debe, como un repositorio público. Guárdalo
como una contraseña.

Desde la misma lista ves cuándo se usó cada token por última vez y cuándo
caduca, puedes **rotarlo** (un valor nuevo con el mismo nombre y permiso; el
anterior deja de funcionar al momento) o **revocarlo**.

### Permisos

Cada endpoint de la [referencia](/documentation/api/reference) indica qué
permiso necesita:

- **read**: cualquier `GET`. Sirven los tokens de solo lectura y los de lectura
  y escritura.
- **write**: todo lo que crea, edita o borra. Un token de solo lectura responde
  `403` con el código `insufficient_scope`.

Por dentro, un token de solo lectura lleva el permiso `api:read`, y uno de
lectura y escritura `api:read` y `api:write`. Un token OAuth lleva el permiso
`api:use` (su scope de OAuth), que cubre los dos.

## Apps OAuth

Una app hecha por otra persona no debería pedirte que pegues un token. Se conecta
con OAuth 2.1 y tú la apruebas en una pantalla de Whisper Money:

1. La app se registra sola con el registro dinámico de clientes:
   `POST https://whisper.money/oauth/register` con su nombre y sus URIs de
   redirección. No hay portal de desarrolladores ni secreto de cliente.
2. Te envía a `https://whisper.money/oauth/authorize` con el scope `api:use` y
   un reto PKCE (`S256`).
3. Inicias sesión y ves la pantalla de consentimiento: el nombre de la app, la
   dirección a la que te devolverá y un aviso de que Whisper Money no la ha
   verificado. La app obtiene acceso de **lectura y escritura** a tus datos.
4. La app canjea el código en `https://whisper.money/oauth/token` y llama a la
   API con el token de acceso que recibe, y lo renueva con el token de refresco
   cuando caduca.

Los documentos de descubrimiento listan estas direcciones para los clientes que
los leen: `/.well-known/oauth-authorization-server` y
`/.well-known/oauth-protected-resource/api/v1`.

Aprueba solo apps de confianza: cualquiera puede registrar una app con cualquier
nombre. En **Ajustes → API → Apps conectadas** ves todas las apps que has
aprobado y cuándo usaron la API por última vez. Revocar una la corta al momento
e invalida todos sus tokens.

Cuenta el plan de quien aprueba la app, y las cuentas de demostración
compartidas no pueden aprobar apps.

## La API y el Conector de IA van por separado

El [Conector de IA](/documentation/ai-connector) (MCP) tiene sus propios tokens
y sus propias apps conectadas, en **Ajustes → Conector de IA**. Nunca sirven en
la API, y los tokens de la API nunca sirven en el Conector de IA: un token del
Conector de IA enviado a `/api/v1` responde `403`. Una app OAuth se aprueba para
uno de los dos, nunca para ambos.
