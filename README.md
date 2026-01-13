# 🏖️ COPASAYBEACH

**COPASAYBEACH** es una aplicación web responsive para la gestión de un torneo de fútbol recreativo, desarrollada con **PHP, HTML, CSS y JSON**.

Permite:
- Definir equipos desde un archivo JSON
- Generar automáticamente el fixture (todos contra todos)
- Cargar goles, tarjetas amarillas y rojas
- Calcular y mostrar la tabla de posiciones
- Visualizar los partidos como tarjetas (mobile-first)
- Compartir los resultados entre distintos dispositivos

🌐 Demo online:  
http://copasaybeach.gamer.gd/

---

## ⚽ Funcionalidades

- 📋 **Equipos configurables** desde `equipos.json`
- 🔁 **Fixture automático** (round-robin)
- 🏟️ **Carga de resultados**:
  - Goles
  - Tarjetas amarillas 🟨
  - Tarjetas rojas 🟥
- 🏆 **Tabla de posiciones** con:
  - Puntos
  - PJ, PG, PE, PP
  - GF, GC, DG
  - Fair Play (🟨 = 1 punto, 🟥 = 3 puntos)
- 🎨 **Interfaz responsive** (desktop y mobile)
- 🌴 **Estética temática “playa”**
- 📱 **Datos compartidos** entre todos los usuarios (persistencia en servidor)

---

## 🧱 Tecnologías utilizadas

- PHP 8.x
- HTML5
- CSS3 (Bootstrap 5)
- JavaScript (mínimo)
- JSON (persistencia de datos)
- XAMPP (entorno local)
- InfinityFree (hosting gratuito)

---

## 📁 Estructura del proyecto

mvp-futbol/
├── index.php
├── equipos.json
├── resultados.json
├── img/
│ └── portada.jpg (opcional)
└── README.md

---

## ▶️ Ejecutar el proyecto en local

### 1️⃣ Requisitos
- Tener instalado **XAMPP**
- Apache en ejecución

### 2️⃣ Pasos
1. Copiar el proyecto en: C:\xampp\htdocs\mvp-futbol
2. Abrir el navegador y entrar a: http://localhost/mvp-futbol

---

## 🌐 Publicación en hosting (InfinityFree)

1. Crear un hosting gratuito en https://infinityfree.net
2. Subir los archivos a la carpeta: /htdocs
3. Asegurar permisos de escritura en: resultados.json → 666
4. Acceder desde el dominio asignado

---

## 📝 Configuración de equipos

Los equipos se definen en el archivo `equipos.json`:

```json
[
{ "id": 1, "nombre": "Equipo #1" },
{ "id": 2, "nombre": "Equipo #2" },
{ "id": 3, "nombre": "Equipo #3" }
]

Luego de modificar este archivo, es necesario volver a generar el fixture.

---

## ⚠️ Consideraciones

Los datos se guardan en archivos JSON compartidos
Si dos usuarios guardan al mismo tiempo, el último guardado sobrescribe
Ideal para eventos y torneos pequeños
Para uso intensivo se recomienda migrar a MySQL

---

## 👩‍💻 Autora

Proyecto desarrollado por LLP como aplicación web práctica para la gestión de eventos deportivos
