# Inventario Taller

Aplicación web para gestión de inventario de taller. Stack: PHP 8.2 + Apache + MySQL 8.0.  
Dockerizada y compatible con **CasaOS Custom Install**.

## Requisitos

- Docker + Docker Compose (v2)
- Puerto 8085 disponible (configurable)

## Instalación desde cero

```bash
git clone https://github.com/zpma82/inventario2.git
cd inventario2
sudo docker compose up -d --build
```

Accede en: `http://<IP>:8085`

La primera vez, el contenedor crea la base de datos y aplica los SQL de `app/sql/` automáticamente (tarda un minuto).

## Actualización

Entra en la carpeta del repositorio y trae los cambios:

```bash
cd /home/jesus/inventario2
git pull
```

> **Historial permanente (migración 13).** Si vienes de una versión anterior, aplica una vez la migración **antes** de desplegar:
> `sudo docker exec -i inventario2_db mysql -uroot -p'<contraseña root>' inventario2 < app/sql/13_historial_permanente.sql`

**A) Actualización rápida** (cambios en `app/api/*.php`, `app/index.html` o `app/img-ies.png`). Copia los ficheros al contenedor en marcha, sin reconstruir nada:

```bash
sudo bash update-casaos.sh
```

**B) Actualización completa** (cambios en `docker/`, `docker-compose.yml`, `app/icon.png` o ficheros nuevos). Reconstruye la imagen y conserva la base de datos:

```bash
sudo docker compose down
sudo docker volume rm inventario2_app_data
sudo docker compose up -d --build
```

> `app_data` guarda los ficheros de la aplicación y solo se rellena la primera vez, por eso hay que borrarlo para que la imagen nueva se aplique. **No** borres `inventario2_db_data` ni uses `down -v`: destruiría la base de datos.

> Los scripts SQL solo se ejecutan cuando la base de datos está vacía. Si una actualización trae un `.sql` nuevo, aplícalo a mano:
> `sudo docker exec -i inventario2_db mysql -uroot -p'<contraseña root>' inventario2 < app/sql/NN_nombre.sql`

## Usuarios por defecto (contraseña: `1234`)

| Usuario  | Rol       |
|----------|-----------|
| admin    | Administrador |
| carlos   | Técnico   |
| laura    | Técnico   |
| pedro    | Usuario   |
| ana      | Usuario   |
| invitado | Solo lectura |

> ⚠️ Cambia las contraseñas antes de usar en producción.

## CasaOS — Custom Install

1. En CasaOS → **App Store → Custom Install**
2. Pega el contenido de `docker-compose.yml`
3. Ajusta el puerto si 8085 ya está ocupado

## Estructura

```
inventario2/
├── app/
│   ├── index.html          # Frontend SPA
│   ├── api/                # Backend PHP
│   │   ├── config.php
│   │   ├── auth.php
│   │   ├── equipos.php
│   │   ├── movimientos.php
│   │   ├── catalogos.php
│   │   └── ubicaciones.php
│   └── sql/                # Scripts SQL (init automático)
│       ├── 01_schema.sql
│       ├── 02_seed.sql
│       └── ...
├── docker/
│   ├── Dockerfile
│   └── apache.conf
├── docker-compose.yml
└── .env.example
```

## Variables de entorno

| Variable             | Valor por defecto              |
|----------------------|-------------------------------|
| APP_PORT             | 8085                          |
| MYSQL_ROOT_PASSWORD  | RootPass_Cambia1!             |
| MYSQL_APP_PASSWORD   | CambiaEstaPassword_Local1!    |

## Comandos útiles

```bash
# Ver logs
docker compose logs -f

# Parar
docker compose down

# Borrar también la BD (⚠️ destruye datos)
docker compose down -v
```

## Historial general y fichero CSV

- **Historial general** (menú lateral): todos los movimientos de todos los productos, también de los **eliminados** (aparecen marcados). Filtros por texto, tipo, fechas y "solo eliminados". Botón para descargar en CSV lo que se ve.
- **Fichero CSV permanente**: cada movimiento (entrada, salida, cambio de ubicación, separación y eliminación) se añade además a `movimientos.csv`. En la VM está en `/DATA/AppData/inventario2/historial/movimientos.csv` (carpeta montada en el contenedor como `/var/www/historial`), así que se ve desde la app **Archivos** de CasaOS y sobrevive a reconstruir o borrar el contenedor. Se descarga desde el propio Historial ("Descargar fichero del servidor"). Usa `;` como separador y UTF-8: Excel y LibreOffice lo abren directamente.
- **Retención** (solo administradores, en el Historial): *Siempre*, *1 mes* o *2 meses*. Al elegir 1 o 2 meses se borran del **fichero** los movimientos más antiguos (una vez al día y al cambiar el ajuste). La base de datos conserva todo el historial.
- El fichero no es accesible por la web directamente: solo se descarga con sesión iniciada.
- Si vienes de una versión anterior, recrea el contenedor para que se monte la carpeta: `sudo docker compose up -d --build`. Si ya tenías un `movimientos.csv` dentro del contenedor, rescátalo antes y devuélvele el propietario:
```bash
  sudo mkdir -p /DATA/AppData/inventario2/historial
  sudo docker cp inventario2_app:/var/www/historial/movimientos.csv /DATA/AppData/inventario2/historial/
  sudo chown -R 33:33 /DATA/AppData/inventario2/historial
```
- Las actualizaciones (rápida y completa) **no tocan** esta carpeta, y tampoco `docker volume rm inventario2_app_data`. Incluye `/DATA/AppData/inventario2/historial/` en tus copias de seguridad.
- Si el fichero no se escribe, el log lo indica (`sudo docker logs inventario2_app 2>&1 | grep historial`: *No se puede escribir en ...*). Casi siempre es un problema de permisos de la carpeta; se arregla con `sudo docker exec inventario2_app chown -R www-data:www-data /var/www/historial`. Un fallo del CSV **no impide** registrar movimientos: el historial de la base de datos se guarda igualmente.
- El visor de archivos de CasaOS puede quedarse "pensando" al abrir un CSV. No es un error: descárgalo y ábrelo con Excel o LibreOffice (o `cat` desde la terminal).
