# Restaurant Smart — Sistema Integral de Gestión de Restaurante

**Universidad Autónoma Juan Misael Saracho**  
**Carrera de Ingeniería Informática**  
**INF401 — Taller II (Software Workshop II)**  
**Metodología:** Scrum — **Sprint 1** (24 Story Points)

---

## 📌 Descripción del Proyecto

**Restaurant Smart** es una plataforma web desarrollada para optimizar la gestión operativa de un restaurante. En este **Sprint 1** se ha construido la base sólida del sistema, incluyendo:

- **US-001 (5 SP):** Autenticación de usuarios mediante **Supabase Auth**, manejo seguro de sesiones, protección de rutas y redirección según rol.
- **US-002 (5 SP):** Gestión completa de usuarios (listado, registro, edición, activación/desactivación y asignación de roles).
- **US-003 (3 SP):** Estructura modular de roles y permisos base (Administrador, Recepcionista, Mesero, Cocinero, Cajero).
- **US-004 (3 SP):** Gestión de clientes (registro, listado, edición, activación/desactivación y búsqueda inteligente).
- **US-005 (8 SP):** Gestión integral de reservas (registro de reservas asignando cliente, fecha, hora, mesa y personas; verificación estricta de capacidad de mesa y prevención de reservas duplicadas).

---

## 🚀 Tecnologías Utilizadas

- **Lenguaje Principal:** PHP 8+
- **Backend & Base de Datos:** Supabase PostgreSQL + Supabase Auth API & PostgREST REST API.
- **Seguridad:** Row Level Security (RLS) & Triggers de PostgreSQL.
- **Diseño & UI:** HTML5, CSS3 personalizado (Glassmorphism, Dark/Light palettes), Bootstrap 5.3 & Bootstrap Icons.
- **Frontend Interactivo:** JavaScript ES6 (Buscador dinámico en vivo, validación de formularios y modales).

---

## 🛠️ Estructura del Proyecto

```text
restaurant-control-system/
├── assets/
│   ├── css/
│   │   └── style.css          # Estilos personalizados y tema del sistema
│   ├── js/
│   │   └── main.js            # Lógica interactiva en vivo
├── config/
│   ├── config.php            # Carga de .env, constantes y helpers
│   └── supabase.php          # Cliente cURL PHP para Supabase Auth y PostgREST API
├── auth/
│   ├── login.php             # Vista de inicio de sesión
│   ├── process_login.php     # Validación y creación de sesión
│   ├── logout.php            # Cierre seguro de sesión
│   └── middleware.php        # Protección de rutas y control de acceso (RBAC)
├── includes/
│   ├── header.php            # Header HTML común
│   ├── navbar.php            # Topbar dinámico
│   ├── sidebar.php           # Menú lateral dinámico por rol
│   ├── footer.php            # Footer y scripts
│   └── alerts.php            # Sistema de notificaciones flash
├── modules/
│   ├── dashboard/            # Panel de control e indicadores
│   ├── usuarios/             # Módulo US-002
│   ├── roles/                # Módulo US-003
│   ├── clientes/             # Módulo US-004
│   ├── mesas/                # Módulo de administración de mesas
│   └── reservas/             # Módulo US-005
├── database/
│   └── schema.sql            # Script PostgreSQL ejecutable en Supabase SQL Editor
├── .env.example              # Plantilla de variables de entorno
├── .gitignore
├── index.php                 # Enrutador principal
└── README.md
```

---

## ⚙️ Pasos para Ejecutar el Proyecto

### 1. Configuración de Supabase (Base de Datos & Auth)

1. Ingresa a tu panel de [Supabase](https://supabase.com/).
2. Ve al **SQL Editor** de tu proyecto.
3. Copia todo el contenido del archivo `database/schema.sql` y ejecútalo (**Run**).
   - Esto creará automáticamente las tablas `roles`, `perfiles`, `clientes`, `mesas`, `reservas`, los índices de unicidad, los triggers de actualización y los datos semilla iniciales.
4. En el panel de Supabase, ve a **Authentication -> Settings** y asegúrate de que el registro por email esté habilitado.

### 2. Configurar el Archivo `.env`

Copia el archivo `.env.example` a un nuevo archivo llamado `.env` en la raíz del proyecto:

```bash
cp .env.example .env
```

Edita `.env` e ingresa la URL y la Anon Key de tu proyecto en Supabase:

```env
APP_NAME="Restaurant Smart"
APP_ENV=development
APP_URL="http://localhost:8000"

SUPABASE_URL="https://tu-proyecto-id.supabase.co"
SUPABASE_ANON_KEY="tu-anon-key-de-supabase"
```

### 3. Iniciar el Servidor de Desarrollo PHP

Abre tu terminal en el directorio del proyecto y ejecuta el servidor integrado de PHP:

```bash
php -S localhost:8000
```

Navega a [http://localhost:8000](http://localhost:8000) en tu navegador.

---

## 🔑 Credenciales para Pruebas

Para crear el **primer usuario Administrador**:

1. Ingresa a Supabase -> **Authentication -> Users -> Add User -> Create User**.
2. Ingresa un email (ejemplo: `admin@restaurante.com`) y una contraseña.
3. El trigger `on_auth_user_created` le asignará perfil automáticamente. Si deseas cambiar su rol a **Administrador**, edita la fila en la tabla `perfiles` estableciendo `rol_id = 1`.
4. Inicia sesión en la interfaz web de Restaurant Smart con ese correo y contraseña.

---

## 📊 Estado de Historias de Usuario (Sprint 1)

| Código | Historia de Usuario | SP | Estado |
|---|---|---|---|
| **US-001** | Inicio de sesión y manejo de sesiones | 5 SP | **Completado** (Supabase Auth + Sessions) |
| **US-002** | Gestión de usuarios y perfiles | 5 SP | **Completado** (CRUD + Supabase Auth) |
| **US-003** | Gestión de roles y permisos | 3 SP | **Completado** (5 Roles base + RBAC Middleware) |
| **US-004** | Gestión de clientes | 3 SP | **Completado** (CRUD + Buscador en vivo) |
| **US-005** | Gestión de reservas | 8 SP | **Completado** (CRUD + Capacidad + Disponibilidad) |
| **TOTAL** | **Sprint 1** | **24 SP** | **100% COMPLETADO** |
