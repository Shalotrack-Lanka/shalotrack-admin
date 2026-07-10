# SHALOTRACK ADMIN PORTAL (huu huu)

## Administrative Control Center for the ShaloTrack GPS Tracking Platform

---

# Executive Summary

## Introduction

The ShaloTrack Admin Portal is the centralized operational management platform for the ShaloTrack GPS Tracking and Fleet Management Ecosystem.

Built using Laravel, the Admin Portal provides administrative, dealer, finance, and technical teams with a secure web-based interface for managing customers, vehicles, GPS devices, subscriptions, activations, payments, troubleshooting operations, and platform administration.

The portal serves as the primary internal management system used by ShaloTrack staff and business partners.

Unlike customer-facing applications, the Admin Portal focuses on operational workflows, device lifecycle management, subscription administration, support services, and platform governance.

---

# Project Vision

## Academic Objective

The ShaloTrack Admin Portal was developed as part of the ShaloTrack Final Year Software Engineering Project.

The system demonstrates:

* Enterprise Web Application Development
* Role-Based Access Control (RBAC)
* Laravel Framework Development
* PostgreSQL Database Integration
* API Driven Architecture
* Cloud Infrastructure Integration
* Authentication & Authorization Systems
* Operational Workflow Management

---

## Commercial Objective

The long-term objective of the Admin Portal is to become the primary operational platform used by:

* ShaloTrack Administrators
* Dealers
* Finance Officers
* Technical Support Teams
* Future Operations Managers
* Future Regional Managers

The portal is designed to support commercial deployment across Sri Lanka and future regional expansion.

---

# About the ShaloTrack Ecosystem

The Admin Portal is one component of the broader ShaloTrack platform.

```text
GPS Devices
      │
      ▼
gateway.shalotrack.com
      │
      ▼
Supabase PostgreSQL
      ▲
      │
api.shalotrack.com
      ▲
      │
admin.shalotrack.com
```

The Admin Portal does not communicate directly with GPS devices.

Instead, it retrieves and manages information through the ShaloTrack API and database infrastructure.

---

# Admin Portal Responsibilities

The Admin Portal is responsible for:

### Customer Operations

* Customer Records
* Customer Information Management
* Customer Support

### Vehicle Operations

* Vehicle Registration
* Vehicle Management
* Vehicle Assignments

### Device Operations

* GPS Device Registration
* Device Assignment
* Device Status Monitoring
* Device Command Management

### Subscription Management

* Subscription Activation
* Subscription Renewal
* Subscription Expiry Monitoring

### Financial Operations

* Payment Tracking
* Invoice Management
* Subscription Billing

### Technical Operations

* Device Diagnostics
* Device Commands
* Troubleshooting
* Device Recovery

### Administrative Operations

* User Management
* Role Management
* Permissions Management
* System Monitoring

---

# System Architecture

```text
GPS Devices
      │
      ▼
ShaloTrack Gateway
      │
      ▼
Supabase PostgreSQL
      ▲
      │
ShaloTrack API
      ▲
      │
Laravel Admin Portal
      ▲
      │
Administrators
Dealers
Finance Users
Technicians
```

---

# Technology Stack

## Frontend

* Blade Templates
* Tailwind CSS
* Alpine.js
* Vite

## Backend

* Laravel 12
* PHP 8+

## Authentication

* Laravel Breeze
* Session Authentication
* Role-Based Access Control

## Authorization

* Spatie Laravel Permission

## Database

* Supabase PostgreSQL

## Infrastructure

* AWS EC2
* Cloudflare DNS
* GitHub

---

# Role-Based Access Control (RBAC)

The Admin Portal uses a Role-Based Access Control architecture.

## Roles

### Administrator

Full system access.

### Dealer

Access limited to assigned customers and devices.

### Finance

Access limited to payments and subscriptions.

### Technician

Access limited to device diagnostics and support tools.

---

# Permission Architecture

Planned permission examples:

```text
customers.view
customers.edit

devices.create
devices.update
devices.delete

payments.view
payments.manage

subscriptions.view
subscriptions.edit
```

The RBAC architecture has been designed to allow future expansion without requiring major application changes.

Future roles may include:

* Customer Support
* Operations Manager
* Regional Manager
* Sales Executive

---

# Database Architecture

## Current Admin Table

```text
admins

admin_id
username
password
full_name
email
phone_number
role
status
last_login_at
created_at
updated_at
```

The authentication system uses the admins table instead of Laravel's default users table.

---

# Authentication Architecture

## Login Process

```text
User
   │
   ▼
Login Screen
   │
   ▼
Laravel Authentication
   │
   ▼
Admins Table
   │
   ▼
Role Detection
   │
   ▼
Dashboard Redirection
```

Authentication is performed using:

* Username
* Password
* Active Status Verification

Users with inactive accounts cannot access the system.

---

# Dashboard Routing

Following authentication, users are redirected based on their assigned role.

## Administrator

```text
/admin/dashboard
```

## Dealer

```text
/dealer/dashboard
```

## Finance

```text
/finance/dashboard
```

## Technician

```text
/technician/dashboard
```

---

# Portal Modules

## Dashboard

System overview and operational metrics.

---

## Customer Management

Features:

* View Customers
* Customer Search
* Customer Profiles

---

## Vehicle Management

Features:

* Vehicle Registration
* Vehicle Assignment
* Vehicle Tracking

---

## Device Management

Features:

* GPS Devices
* Device Assignments
* Device Status Monitoring

---

## Command Center

Features:

* Device Commands
* Device Diagnostics
* Device Recovery Operations

---

## Tracking

Features:

* Current Locations
* Tracking History
* Route Information

---

## Alerts

Features:

* Device Alerts
* Tracking Alerts
* System Notifications

---

## Geofences

Features:

* Geofence Creation
* Geofence Monitoring
* Geofence Management

---

## Subscriptions

Features:

* Subscription Activation
* Subscription Renewal
* Subscription Tracking

---

## Payments

Features:

* Payment Records
* Invoice Tracking
* Billing Management

---

## Notifications

Features:

* Customer Notifications
* Device Notifications
* System Notifications

---

## Administration

Features:

* User Management
* Role Management
* Permission Management
* System Logs

---

# Operational Workflow Modules

Based on business requirements, the portal includes specialized operational modules.

## Master Pages

* Products
* Features
* Price Groups
* Price Group Details
* Product Management

---

## Suppliers

* Add Supplier
* Purchase Records
* Stock Uploads

---

## Dealers

* Dealer Management
* Distributor Management
* Retailer Management

---

## Device Management

* Device Registration
* SIM Activation
* Device Testing

---

## Activations

* Subscription Activation
* Device Activation

---

## Complaints & Enquiries

* Customer Comments
* Support Requests
* Support Ticket Management

---

## Troubleshooting

Features include:

* IMEI Search
* Mobile Number Search
* SIM Number Search
* Device Command Execution
* Remote Device Reboot

These tools support field diagnostics and customer support operations.

---

# API Integration Architecture

The Admin Portal follows an API-driven architecture.

```text
Admin Portal
      │
      ▼
api.shalotrack.com
      │
      ▼
Business Services
      │
      ▼
Supabase PostgreSQL
```

Benefits:

* Centralized Business Logic
* Improved Security
* Easier Maintenance
* Consistent Validation

---

# Hosting Architecture

Planned Production Deployment:

```text
admin.shalotrack.com
        │
        ▼
Cloudflare DNS
        │
        ▼
AWS EC2
        │
        ▼
Laravel Application
        │
        ▼
ShaloTrack API
        │
        ▼
Supabase PostgreSQL
```

Cloudflare provides:

* DNS Management
* SSL/TLS
* DDoS Protection

AWS EC2 provides:

* Laravel Hosting
* Background Jobs
* Future Queue Services

---

# Development History

## Day 01

* Requirements Gathering
* Workflow Planning
* Admin Portal Design

## Day 02

* Laravel Project Creation
* Initial Repository Setup

## Day 03

* Laravel Breeze Installation
* Authentication Foundation

## Day 04

* Supabase Database Integration

## Day 05

* Admin Model Development
* Custom Authentication System

## Day 06

* Role-Based Authentication
* Dashboard Routing

## Day 07

* Navigation Layout Development
* Sidebar Architecture
* Dashboard Structure

## Day 08

* GitHub Organization Integration
* Repository Synchronization

## Day 09

* RBAC Planning
* Permission Architecture Design

---

# Current Project Status

| Component           | Status      |
| ------------------- | ----------- |
| Laravel Setup       | Completed   |
| Supabase Connection | Completed   |
| Authentication      | Completed   |
| Custom Admin Model  | Completed   |
| Role-Based Login    | Completed   |
| Dashboard Routing   | Completed   |
| Navigation System   | Completed   |
| RBAC Design         | Completed   |
| Dashboard UI        | In Progress |
| User Management     | Planned     |
| Device Management   | Planned     |
| API Integration     | In Progress |

---

# Development Roadmap

## Phase 1

Core Infrastructure

## Phase 2

Authentication & Authorization

## Phase 3

Role-Based Access Control

## Phase 4

Dashboard Development

## Phase 5

Operational Modules

## Phase 6

API Integration

## Phase 7

Reporting & Analytics

## Phase 8

Production Deployment

---

# Contributors

The ShaloTrack Admin Portal is part of the larger ShaloTrack GPS Tracking and Fleet Management Ecosystem and has been developed through the combined efforts of business stakeholders, software developers, and system architects.

---

## Founder & Product Owner

**Nuwan Aloka**

Nuwan Aloka is the founder and owner of the ShaloTrack platform.

Responsibilities include:

* Product Vision
* Business Strategy
* Commercial Planning
* Customer Relations
* Market Development
* Operational Requirements
* Product Roadmap Planning
* Project Oversight

As Product Owner, Nuwan Aloka is responsible for defining the business objectives, operational workflows, service offerings, and long-term direction of the ShaloTrack ecosystem.

---

## Lead Admin Portal Developer

**Nuwan Akalanka**

Nuwan Akalanka is responsible for the development of the ShaloTrack Administrative Portal.

Responsibilities include:

* Laravel Application Development
* Authentication System Development
* Role-Based Access Control (RBAC)
* Dashboard Development
* Administrative Interface Design
* Supabase Database Integration
* Administrative Workflow Implementation
* User Management Functionality
* Portal Navigation Architecture
* Business Process Implementation

Development Contributions:

* Laravel Project Setup
* Laravel Breeze Authentication
* Admin Authentication System
* Role-Based Login Architecture
* Dashboard Routing
* Admin Database Model Integration
* Initial Portal Layout Development
* Administrative Module Planning
* Repository Management

The ShaloTrack Admin Portal repository primarily contains the work contributed by Nuwan Akalanka.

---

## System Architect & Platform Backend Developer

**Swen Jayathunga**

Swen Jayathunga is responsible for the overall technical architecture of the ShaloTrack ecosystem.

Responsibilities include:

* System Architecture Design
* Platform Architecture Planning
* Gateway Development
* ASP.NET Core API Development
* Database Architecture Design
* Infrastructure Engineering
* AWS Cloud Deployment
* Cloudflare Integration
* Service Integration Planning
* Scalability Architecture
* Security Architecture

Development Contributions:

* ShaloTrack Gateway Development
* TCP Communication Infrastructure
* GT06 Protocol Integration
* V5 GPS Device Integration
* Backend API Development
* PostgreSQL Database Architecture
* Cloud Infrastructure Design
* Ecosystem Integration Architecture

---

## Development Ownership

| Area | Lead Contributor |
|---|---|
| Product Ownership | Nuwan Aloka |
| Business Operations | Nuwan Aloka |
| Product Requirements | Nuwan Aloka |
| Admin Portal Development | Nuwan Akalanka |
| Authentication System | Nuwan Akalanka |
| Dashboard Development | Nuwan Akalanka |
| Laravel Development | Nuwan Akalanka |
| Gateway Development | Swen Jayathunga |
| API Development | Swen Jayathunga |
| Database Architecture | Swen Jayathunga |
| Cloud Infrastructure | Swen Jayathunga |
| System Architecture | Swen Jayathunga |

---

## Acknowledgements

The ShaloTrack Admin Portal is developed as part of the broader ShaloTrack GPS Tracking and Fleet Management Platform.

This repository focuses on the administrative and operational management capabilities of the platform and forms one component of the larger ShaloTrack ecosystem, which includes:

* ShaloTrack Gateway
* ShaloTrack API
* ShaloTrack Mobile Application
* ShaloTrack Website
* ShaloTrack Administrative Portal

Together, these systems provide a complete GPS tracking, fleet management, and operational monitoring solution designed for both academic research and future commercial deployment.

---

# Long-Term Vision

The ShaloTrack Admin Portal is being developed as a comprehensive operational management platform capable of supporting:

* GPS Tracking Operations
* Fleet Management
* Device Lifecycle Management
* Dealer Operations
* Subscription Management
* Customer Support
* Financial Management
* Analytics & Reporting

As the platform grows, the Admin Portal will remain the central operational command center for all internal ShaloTrack business activities.

---

**ShaloTrack Admin Portal**

Version: v0.1.0

Status: Active Development

Built with ❤️ by the ShaloTrack Development Team.
