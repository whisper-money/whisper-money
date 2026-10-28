# API

La API de Whisper Money permite que tus propios scripts y apps lean tus datos y los cambien por HTTP: cuentas, transacciones, categorías, presupuestos y el resto de lo que ves en la app.

{{TOC}}

## Qué es

Una API REST que responde en JSON. Llega a tus propios datos, los mismos que ves
en la app, y a los de nadie más. Está pensada para automatizar tus finanzas: un
script que exporta cada noche tus transacciones a una hoja de cálculo, un panel
casero o una app que te haces para ti.

Si lo que quieres es preguntarle por tu dinero a un asistente como Claude o
ChatGPT, no necesitas la API: el [Conector de IA](/documentation/ai-connector)
lo hace sin escribir código.

La API forma parte del plan de pago. Si la suscripción termina, los tokens se
mantienen, pero todas las peticiones responden `403` hasta que vuelvas a
suscribirte.

## URL base

Todos los endpoints cuelgan de:

```text
https://whisper.money/api/v1
```

La API solo habla HTTPS y JSON. Envía `Accept: application/json`, y
`Content-Type: application/json` en cualquier petición con cuerpo.

## Tu primera petición

1. Abre **Ajustes → API** y crea un token. Cópialo: solo se muestra una vez.
2. Lista tus espacios con él:

```bash
curl https://whisper.money/api/v1/spaces \
  -H "Authorization: Bearer wm_api_tu_token"
```

La respuesta son tus espacios, dentro de `data`:

```json
{
    "data": [
        {
            "id": "0199a3f2-6c1e-7b4d-9a8e-2f5c1d3b4a60",
            "name": "Personal",
            "personal": true,
            "owned": true,
            "created_at": "2026-01-15T09:30:00+00:00"
        }
    ]
}
```

El resto de ejemplos de estas páginas leen el token de una variable de entorno,
así que defínela una vez en tu terminal:

```bash
export WHISPER_MONEY_TOKEN=wm_api_tu_token
```

## Cómo se comporta

Estas páginas describen lo que todos los endpoints tienen en común. Léelas una
vez antes de escribir un cliente:

- [Autenticación](/documentation/api/authentication): tokens personales, apps
  OAuth y los permisos de cada uno.
- [Importes y monedas](/documentation/api/amounts-and-currencies): los importes
  son enteros en la unidad más pequeña de cada moneda.
- [Espacios](/documentation/api/spaces): el parámetro `space`.
- [Paginación](/documentation/api/pagination): cómo se paginan las listas.
- [Errores](/documentation/api/errors): la forma de todos los errores.
- [Límites de uso](/documentation/api/rate-limits): 60 peticiones por minuto.
- [Idempotencia](/documentation/api/idempotency): reintentar una creación sin
  riesgo.
- [Changelog](/documentation/api/changelog): qué ha cambiado y cuándo.

La [referencia de la API](/documentation/api/reference) lista todos los
endpoints, con sus parámetros, una petición de ejemplo y una respuesta de
ejemplo. Está solo en inglés, porque se genera a partir del código.

## Especificación OpenAPI

Toda la API está descrita en un documento OpenAPI 3.1 en
[https://whisper.money/api/v1/openapi.json](https://whisper.money/api/v1/openapi.json).
No necesita token, así que puedes cargarlo en Postman, Insomnia o un generador
de código, o dárselo a un agente de IA para que te escriba el cliente.
