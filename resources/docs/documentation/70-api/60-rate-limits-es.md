# Límites de uso

Cada usuario puede hacer 60 peticiones por minuto, sumando todos sus tokens y apps conectadas.

{{TOC}}

## El límite

El límite es por usuario, no por token: tres scripts con tres tokens comparten
las mismas 60 peticiones por minuto. Las respuestas te dicen cómo vas:

| Cabecera                | Significado                                   |
| ----------------------- | --------------------------------------------- |
| `X-RateLimit-Limit`     | Peticiones permitidas por minuto: `60`.       |
| `X-RateLimit-Remaining` | Peticiones que te quedan en el minuto actual. |

## Cuando te pasas

Por encima del límite, la API responde `429` con el código `too_many_requests` y
dos cabeceras más:

| Cabecera            | Significado                                              |
| ------------------- | -------------------------------------------------------- |
| `Retry-After`       | Segundos que esperar antes de la siguiente petición.     |
| `X-RateLimit-Reset` | Cuándo se reinicia el límite, como marca de tiempo Unix. |

Espera lo que indica `Retry-After` y vuelve a intentarlo. Las peticiones que
respondieron `429` no hicieron nada, así que reintentarlas es seguro.

## Cómo no pasarte

- Pide páginas completas: `limit=100` trae los mismos datos con la cuarta parte
  de peticiones que el valor por defecto, 25.
- Guarda lo que ya has descargado en vez de pedirlo otra vez.
- Reparte las peticiones en el tiempo en lugar de enviarlas de golpe.

La especificación OpenAPI de `/api/v1/openapi.json` no necesita token y no cuenta
para el límite.
