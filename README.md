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
