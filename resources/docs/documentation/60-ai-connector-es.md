# Conector de IA

El Conector de IA permite que un asistente que ya usas, Claude o ChatGPT, lea tus datos de Whisper Money y los modifique, para que puedas preguntar por tu dinero en lenguaje normal en lugar de ir pasando por pantallas.

{{TOC}}

## Inicio rápido

1. Abre **Ajustes → Conector de IA**.
2. Elige tu app en **Cómo conectar** y copia la URL que aparece.
3. En Claude o ChatGPT, añade esa URL como conector personalizado.
4. Inicia sesión y aprueba la conexión en la pantalla de Whisper Money que se
   abre.
5. Pregúntale algo: «¿cuánto me he gastado este mes en la compra?»

Haz esta parte en un ordenador. Iniciar sesión y aprobar funciona bien en el
navegador de un ordenador, pero suele fallar en el navegador interno del móvil.
Una vez conectado, hablar con Whisper Money desde el móvil funciona con
normalidad.

El Conector de IA forma parte del plan de pago.

## Qué es MCP

MCP son las siglas de Model Context Protocol. Es la forma estándar de dar a un
asistente de IA acceso a los datos de una app, y es lo que habla el Conector de
IA.

No necesitas saber nada de él para usar esto. Lo que importa es qué significa en
la práctica:

- El asistente no se lleva una copia de tu base de datos. Le hace una pregunta a
  Whisper Money en el momento en que necesita una respuesta, y recibe solo esa
  respuesta.
- Cada petición se hace como tú. Ve exactamente lo que ve tu cuenta, y nada de
  la de nadie más.
- Tú decides cuándo existe la conexión. No hay nada conectado hasta que lo
  conectas, y quitarlo corta el acceso al instante.

Whisper Money expone 32 de esas preguntas y acciones, llamadas herramientas: 11
que leen tus datos y 21 que los modifican. El asistente elige las que necesita
por su cuenta; tú escribes en lenguaje normal.

## Qué le puedes pedir

<div class="cards-wrapper">

<div class="card">
### Transacciones

Busca en tu historial, y crea, edita, elimina, categoriza, etiqueta, divide y
unifica transacciones.

Prueba:

- «¿Qué me gasté en el supermercado en marzo?»
- «Apúntame 40 euros de compra de ayer.»
- «Divide la cena de anoche entre comida y bebida.»

</div>

<div class="card">
### Cuentas y saldos

Lista tus cuentas con sus saldos, y registra un saldo nuevo en una cuenta de las
que llevas por valor.

Prueba:

- «¿Qué cuentas tengo y cuánto hay en cada una?»
- «Mi cuenta del bróker está hoy en 12.400.»

</div>

<div class="card">
### Categorías y etiquetas

Lista, crea, renombra y elimina tus categorías y tus etiquetas.

Prueba:

- «Crea una categoría de Mascotas.»
- «¿Qué etiquetas no estoy usando de verdad?»

</div>

<div class="card">
### Presupuestos

Consulta cómo va el periodo en curso, y crea, edita o elimina un presupuesto.

Prueba:

- «¿Cómo voy de presupuesto?»
- «Créame un presupuesto de 300 al mes para comer fuera.»

</div>

<div class="card">
### Reglas de automatización

Lista tus reglas, escribe otras nuevas, cámbialas y aplica una a las
transacciones que ya tienes en la cuenta.

Prueba:

- «Todo lo de Netflix debería ir a Suscripciones.»
- «Aplica esa regla a mis transacciones pasadas.»

</div>

<div class="card">
### Flujo de efectivo, patrimonio y gasto

Las mismas cifras que muestran la pantalla de Flujo de efectivo y el panel, para
el periodo que le pidas.

Prueba:

- «¿En qué se me está yendo el dinero este año?»
- «¿Ha crecido mi patrimonio desde enero?»

</div>

<div class="card">
### Espacios

Lista tu espacio personal y los espacios compartidos contigo, para poder
preguntar por uno en concreto.

Prueba:

- «¿Cuánto ha gastado el espacio de casa este mes?»

</div>

<div class="card">
### Medallas

Consulta tu catálogo de Progreso: las medallas que has conseguido, la siguiente
que estás persiguiendo y tu racha de ahorro.

Prueba:

- «¿Cuál es la próxima medalla que puedo conseguir?»

</div>
</div>

Nada de esto es un comando que tengas que recordar. Pregunta con tus palabras y
el asistente decide qué herramientas usar.

## Conectar Claude

Vale para Claude Desktop y para Claude en la web.

1. Abre **Ajustes → Conectores** en Claude.
2. Haz clic en **Añadir** arriba a la derecha y luego en **Añadir conector
   personalizado**.
3. Ponle un nombre y pega la URL de **Ajustes → Conector de IA**, la que termina
   en `/mcp/oauth`.
4. Aprueba la conexión en la pantalla de Whisper Money que se abre.

Inicias sesión con tu cuenta de Whisper Money y apruebas la conexión en una
pantalla que servimos nosotros. No hay ningún token que crear ni pegar, y Claude
nunca ve tu contraseña.

## Conectar ChatGPT

1. Activa el modo desarrollador: **Ajustes → Seguridad e inicio de sesión → Modo
   desarrollador**. Los conectores personalizados solo existen con él activado.
2. En **Plugins**, haz clic en el botón **+** de arriba a la derecha.
3. Ponle un nombre y pega la misma URL que termina en `/mcp/oauth`.
4. Aprueba la conexión en la pantalla de Whisper Money que se abre.

## Conectar Claude Code

Claude Code es la línea de comandos para desarrolladores, y inicia sesión con un
token en lugar del flujo del navegador. Cualquier otra persona debería usar una
de las dos secciones anteriores.

Abre la sección **Conectar con Claude Code** en **Ajustes → Conector de IA**,
crea un token y ejecuta:

```text
claude mcp add --transport http whisper-money https://whisper.money/mcp --header "Authorization: Bearer <token>"
```

Copia el comando exacto de la página en lugar de este, y pon tu propio token en
lugar de `<token>`.

### Tokens

Un token es una contraseña para una conexión. Hay dos cosas que elegir al
crearlo:

- **Solo lectura** puede buscar, analizar e informar, y no puede modificar nada
  nunca.
- **Lectura y escritura** puede además crear, editar y eliminar transacciones,
  categorías, etiquetas, presupuestos y reglas de automatización.

Un token se muestra una sola vez, al crearlo, así que cópialo en un lugar seguro
en ese momento. La página guarda el nombre, el nivel de acceso y las fechas de
creación y de último uso, que es como distingues un token vivo de uno que se te
había olvidado.

Dos cosas que puedes hacerle a un token después:

- **Rotarlo** si se filtra. Conserva su nombre y su nivel de acceso y recibe un
  secreto nuevo, y lo que use el anterior deja de funcionar hasta que lo
  reconectes.
- **Revocarlo** para cortar el acceso. Surte efecto al instante y no se puede
  deshacer.

Los tokens son solo para Claude Code. Una conexión de Claude o de ChatGPT no
tiene ningún token detrás, así que no hay nada que rotar ni nada que se pueda
filtrar.

## Qué puede y qué no puede modificar

Una conexión hecha desde Claude o ChatGPT puede leer tus datos y modificarlos.
Una conexión de Claude Code solo puede modificarlos si su token es de lectura y
escritura.

Dónde se para la escritura es lo mismo en los dos casos, y no es una cuestión de
confianza. Son las reglas que sigue la propia app:

- **Las transacciones sincronizadas del banco y las importadas no se pueden
  editar ni eliminar.** Solo las creadas a mano. Cualquier transacción, venga de
  donde venga, sí se puede categorizar, etiquetar y dividir.
- **Se pueden añadir transacciones nuevas a cualquier cuenta**, incluidas las
  conectadas al banco. Una sincronización solo trae filas que no ha visto antes,
  así que nunca elimina lo que has añadido tú.
- **Los saldos solo se pueden registrar en cuentas que no estén conectadas a un
  banco.** El saldo de una cuenta conectada viene del banco, y una cifra escrita
  a mano la sobrescribiría la siguiente sincronización.
- **El periodo de un presupuesto, su día de inicio, el arrastre y las categorías
  que sigue quedan fijos al crearlo.** Para cambiar cualquiera de esas cosas hay
  que eliminar el presupuesto y crearlo de nuevo.
- **Aplicar una regla de automatización a transacciones pasadas se avisa
  antes.** El asistente informa de cuántas transacciones encajan y no cambia
  nada hasta que le dices que siga.

Eliminar es eliminar de verdad, igual que pulsar el botón en la app. Si
prefieres que no se toque nada, conecta Claude Code con un token de solo
lectura; una conexión de Claude o de ChatGPT siempre es de lectura y escritura.

## Privacidad

Whisper Money no comparte tus datos con nadie. Esta es la única función en la
que los datos salen de la app, así que aquí está exactamente qué pasa.

- No se conecta nada hasta que lo conectas tú, desde tus propios ajustes.
- Mientras está conectado, el asistente lee tus datos para poder responderte, y
  esas conversaciones viven en tu propia cuenta de ese asistente, con sus normas
  y no con las nuestras. No las vemos y no podemos controlar qué hacen con
  ellas.
- Solo se envía lo que tu pregunta necesita. Preguntar por la compra del mes
  pasado no entrega tu historial completo.
- Cuando quieras salir, quitas Whisper Money de las apps conectadas dentro de
  Claude o de ChatGPT, o eliminas el token en el caso de Claude Code. Deja de
  responder al instante.

Conéctalo solo si te parece bien ese intercambio. Si no lo conectas nunca, para
tu cuenta no cambia nada.

## Preguntas frecuentes

### ¿Por qué esto necesita el plan de pago?

Cada pregunta que hace tu asistente ejecuta una consulta real sobre tus datos,
en nuestros servidores. El plan de pago es lo que paga eso. La comprobación se
hace en cada petición, así que no es algo que configures una vez y se quede.

### ¿Puedo configurarlo con el plan gratuito?

Puedes conectarlo y la conexión se hará, pero las respuestas volverán pidiéndote
que mejores el plan. No se rompe nada ni se pierde nada; empieza a funcionar en
el momento en que pasas al plan de pago.

### Funcionaba y ahora no. ¿Qué ha pasado?

Lo más probable es que la suscripción haya caducado. La conexión sigue ahí y
sigue aprobada, pero cada petición se comprueba contra tu plan, así que deja de
responder en lugar de desconectarse. Al renovar vuelve sin nada que reconectar.

La otra posibilidad, solo en Claude Code, es un token que se rotó o se revocó.
Vuelve a añadir la conexión con el secreto nuevo.

### ¿Puede perder o destrozar mis datos?

Puede modificar y eliminar las mismas cosas que puedes tú, así que trátalo como
tratarías dejarle el portátil a alguien. Hay dos cosas que limitan el daño: las
transacciones que vinieron del banco o de una importación no se pueden editar ni
eliminar en absoluto, y un token de solo lectura de Claude Code puede analizarlo
todo y no modificar nada.

### ¿Uso Claude Desktop o Claude Code?

Claude Desktop, salvo que ya vivas en una terminal. Son los mismos datos y las
mismas herramientas en los dos casos. Claude Desktop te identifica por el
navegador y no necesita ningún token; Claude Code necesita un token que crees y
pegues, y a cambio te da la opción de solo lectura.

### ¿Por qué me falla la conexión en el móvil?

El paso de iniciar sesión y aprobar se abre en el navegador interno de Claude o
de ChatGPT, y ese navegador normalmente no puede completarlo. Conéctalo en un
ordenador. Después la conexión es de tu cuenta, así que preguntar desde el móvil
funciona con normalidad.

### ¿Puedo conectar más de un asistente?

Sí. Claude y ChatGPT pueden estar conectados a la vez, y Claude Code junto a
ellos con su propio token. Son conexiones separadas y quitar una deja las otras
como estaban.

### ¿Cómo lo desconecto?

En Claude o en ChatGPT, quita Whisper Money de la lista de apps o plugins
conectados. Para Claude Code, revoca el token en **Ajustes → Conector de IA**.
Después no hay nada que deshacer por nuestra parte.

### ¿Hay un límite de cuánto puedo preguntar?

Hay un techo de 60 peticiones por minuto, que está muy por encima de lo que
consume una conversación. Una sola pregunta suele costar unas pocas peticiones,
así que solo llegarías a él si algo estuviera dando vueltas en bucle.

### ¿Puedo probarlo con la cuenta de demostración?

No. La cuenta de demostración es compartida y su acceso es público, así que nunca
se puede conectar a un asistente.
