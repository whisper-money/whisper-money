# Changelog

Cada cambio de la API que pueda afectar a tu código, del más reciente al más antiguo.

{{TOC}}

## v1: primera versión

La primera versión pública de la API, en `https://whisper.money/api/v1`.

## Cómo funcionarán las versiones

`/api/v1` se queda como está. No quitamos campos ni endpoints, ni cambiamos lo
que significan.

Cuando haga falta un cambio incompatible, llegará como una versión con fecha que
eliges con la cabecera `Whisper-Version`, y las peticiones sin la cabecera
seguirán comportándose como hoy. Esa cabecera aún no está activa: enviarla ahora
no tiene efecto. Los cambios que no pueden romper un cliente, como un endpoint
nuevo o un campo nuevo en una respuesta, se añaden directamente a la v1 y se
anotan aquí.
