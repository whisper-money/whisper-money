# Dashboards personalizados

Además del panel que tiene cualquier cuenta, puedes montar dashboards propios:
eliges los bloques que quieres, les das tamaño y los pones en el orden en que
los lees. Tu asistente de IA también puede escribir bloques nuevos para ellos,
los widgets, a partir de una petición normal como «un gráfico de lo que gasto
en el súper cada mes».

{{TOC}}

## Inicio rápido

1. Abre **Panel** y pulsa el nombre del dashboard junto al título (al principio,
   **General**).
2. Elige **Nuevo dashboard**, ponle nombre y pulsa **Crear**.
3. Pulsa **Añadir widget** y elige los bloques que quieras.
4. Arrastra cada bloque por su asa hasta su sitio y elige su tamaño en su menú.
5. Pulsa **Hecho**.

Para que tu asistente escriba un widget, conéctalo primero con el
[Conector de IA](/documentation/ai-connector) y pídeselo: «Hazme un widget en mi
dashboard de Gastos con lo que gasto en el supermercado cada mes».

Los dashboards personalizados son parte del plan Standard.

## Tus dashboards

El nombre junto al título **Panel** cambia de uno a otro. **General** es el que
tiene cualquier cuenta, el que se describe en [Panel](/documentation/dashboard);
no se puede cambiar ni borrar. Debajo están los tuyos, hasta 20.

**Gestionar dashboards**, en el mismo menú, los lista:

- La estrella lo hace **predeterminado**: el que se abre al ir al Panel. Si no
  hay ninguno, se abre General.
- El lápiz le cambia el nombre y la papelera lo borra, con los widgets que
  tiene colocados. Borrar un dashboard no borra los widgets que escribió tu
  asistente: siguen en tu lista, listos para colocarlos en otro.
- Las asas ponen los dashboards en el orden en que los lista el menú.

## Widgets y tamaños

Un dashboard puede tener los bloques de General (patrimonio neto, cuentas,
ahorro mensual, principales categorías, principales etiquetas y flujo de caja) y
los widgets que escribió tu asistente, hasta 50 en total.

Pulsa **Editar** para cambiarlo. Cada bloque tiene entonces una barra para
arrastrarlo, un menú para elegir su tamaño y una cruz para quitarlo del
dashboard. Quitar un bloque no lo borra: puedes volver a añadirlo desde
**Añadir widget**.

Cada bloque tiene uno de unos pocos tamaños fijos, para que todos los dashboards
queden alineados:

| Ancho              | Ocupa                | Alto        | Ocupa     |
| ------------------ | -------------------- | ----------- | --------- |
| **Un tercio**      | Un tercio de la fila | **Mediano** | Una fila  |
| **Mitad**          | Media fila           | **Grande**  | Dos filas |
| **Ancho completo** | La fila entera       |             |           |

Un bloque grande mide exactamente lo mismo que dos medianos apilados. Algunos
bloques solo admiten algunos de estos tamaños (una tabla ancha no cabe en un
tercio), y el menú solo ofrece esos. En una tableta un tercio ocupa media fila,
y en el móvil todos los bloques ocupan el ancho completo y conservan su alto.

## Widgets que escribe tu asistente

Con el [Conector de IA](/documentation/ai-connector) conectado, tu asistente
puede escribirte un widget. Describe qué quieres ver y en qué dashboard:

- «Añade a mi dashboard de Gastos un widget con lo que gasto en el supermercado
  cada mes».
- «Hazme una tabla con mis cinco gastos más grandes de este mes, a ancho
  completo».
- «Cambia el widget del súper para que enseñe solo los últimos 6 meses».

El asistente escribe el código del widget, dice qué datos tuyos lee y lo coloca
en el dashboard. Abre el dashboard para verlo; puede que tu asistente también
te enseñe una vista previa en el chat. Pide los cambios igual, y la próxima vez
que abras el dashboard el widget ya los tiene.

Tu asistente también puede listar tus dashboards, crear otros nuevos y quitar
un widget de un dashboard. Cambiar el nombre, ordenar y borrar dashboards se
hace en la aplicación.

Un widget se escribe en el idioma en que lo pediste: no cambia aunque cambies el
idioma de la aplicación.

### Cuando un widget falla

Si un widget falla al cargar o al dibujarse, su hueco en el dashboard lo dice y
el resto del dashboard sigue funcionando. El error se guarda con el widget, así
que basta con decirle a tu asistente «el widget del súper está roto,
arréglalo»: lee el error y escribe una versión nueva.

Si un widget no ha podido leer sus datos, su hueco también lo dice. **Datos
parciales** quiere decir que una de sus listas era más larga de lo que un
widget puede leer (10.000 filas), y se dibuja con las primeras.

### Versiones

Cada cambio de tu asistente se guarda como una versión nueva, las últimas 50.
Para volver atrás, abre el menú del widget, elige **Ver el error y las
versiones** y pulsa **Volver a esta** junto a la versión que quieras. Volver
atrás la guarda como versión nueva, así que siempre puedes regresar a la que
dejaste.

### Modo seguro

Un widget que se queda colgado puede congelar la pestaña. Si un dashboard no
terminó de cargar la última vez, la siguiente se abre en **modo seguro**: se
ven los bloques de General y los widgets de tu asistente no se cargan. Quita o
arregla el que da problemas y pulsa **Cargar widgets**.

También puedes abrir el modo seguro tú mismo añadiendo `?safe=1` a la dirección
del dashboard.

## Privacidad

Los widgets están hechos para que tus datos no salgan de Whisper Money:

- **Un widget no puede conectarse a internet.** Su código se revisa antes de
  guardarlo, y se rechaza todo lo que podría enviar datos fuera (una petición,
  un enlace, una imagen de otra web).
- **Solo lee lo que tú puedes leer**, a través de Whisper Money, igual que la
  aplicación: tus datos, nunca los de otra persona. No puede cambiar nada.
- **Es tuyo.** Nadie más ve tus dashboards ni tus widgets, y se borran con tu
  cuenta.

El código del widget lo escribe tu asistente, así que la conversación en la que
lo pides sigue las reglas de tu asistente, como se explica en la
[sección de privacidad del Conector de IA](/documentation/ai-connector#privacidad).
Una vez guardado, abrir el widget no le envía nada a tu asistente.

El **modo privado** desenfoca los importes de los widgets igual que en el resto
de la aplicación, siempre que el widget use el formato de importe que le da
Whisper Money, que es lo que se le pide a tu asistente. Si un widget enseña un
importe sin desenfocar, pídele a tu asistente que lo arregle.

## Plan

Los dashboards personalizados y los widgets son parte del plan Standard, y no
están disponibles en la cuenta demo.

Si tu plan termina, no se borra nada:

- **General** vuelve a abrirse como tu dashboard.
- Tus dashboards siguen en el menú. Al abrir uno ves su distribución con todos
  los bloques desenfocados y un aviso para suscribirte: mientras no tienes el
  plan no se cargan datos ni se ejecuta ningún widget.
- Al crear un dashboard, o al pedirle un widget a tu asistente, te avisamos de
  que es parte del plan Standard.

Cuando vuelvas a suscribirte, todos tus dashboards y widgets vuelven tal como
los dejaste.

## Preguntas frecuentes

### ¿Puedo escribir un widget yo mismo?

Desde la aplicación no. Los widgets los escribe tu asistente a través del
[Conector de IA](/documentation/ai-connector). Puedes colocar, cambiar de
tamaño, quitar y volver a una versión anterior de cualquiera de ellos.

### Mi asistente dice que no puede crear el widget. ¿Por qué?

Te dice el motivo. Lo habitual es que el widget lea datos que no existen (una
categoría que se borró, por ejemplo) o use algo que los widgets no pueden usar,
como un enlace a otra web. Entonces escribe una versión que funcione o te
pregunta qué querías decir.

### ¿Hay algún límite?

20 dashboards, 50 bloques en cada uno y 100 widgets escritos por tu asistente.
