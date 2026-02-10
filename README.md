# 🏆 MEETX - Sistema de Gestión de Deportiva

MEETX es un sistema web completo para la gestión y administración de torneos deportivos, compatible con múltiples deportes (fútbol, vóley y handball). Diseñado con una interfaz moderna y responsive, ideal para organizadores de eventos deportivos.

🌐 Demo online:  
https://copasaybeach.infinityfreeapp.com/

## 🎮 Multi-deporte
- ⚽ Beach Fútbol - Sistema de 3 puntos por victoria
- 🏐 Beach Vóley - Sistema de sets y 2 puntos por victoria
- 🤾 Beach Handball - Sistema de sets similar al vóley

## 📊 Gestión Completa
- ✅ Tabla de posiciones automática con diferentes criterios por deporte
- 📅 Fixture inteligente (todos contra todos o por grupos)
- 🎯 Resultados en tiempo real con cálculo automático de puntos
- 🕐 Calendario de partidos con gestión de fechas y horarios
- 📱 Diseño responsive que funciona en móviles y escritorio

## 👥 Roles de Usuario
- 👑 Administrador - Control total del sistema
- 👥 Jugadores/Visitantes - Solo visualización de dato

## 📁 Estructura del proyecto

- **index.php** - Página principal de la aplicación
- **equipos.json** - Base de datos de equipos
- **resultados.json** - Registro de resultados
- **usuarios.json** - Usuarios del sistema
- **fixture_config.json** - Configuración de torneos
- **deporte_config.json** - Configuración de deportes
- **README.md** - Este archivo

## ⚠️ Consideraciones

Los datos se guardan en archivos JSON compartidos
Si dos usuarios guardan al mismo tiempo, el último guardado sobrescribe
Ideal para eventos y torneos pequeños
Para uso intensivo se recomienda migrar a MySQL

## 👩‍💻 Autora

Proyecto desarrollado por LLP como aplicación web práctica para la gestión de eventos deportivos
