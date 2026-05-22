<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'Panel Admin') - Chambealo</title>
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    
    <style>
        :root {
            --primary-color: #2c3e50;
            --secondary-color: #3498db;
            --danger-color: #e74c3c;
            --success-color: #27ae60;
            --warning-color: #f39c12;
        }
        
        body {
            background: #ecf0f1;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        /* Navbar */
        .navbar {
            background: linear-gradient(135deg, var(--primary-color) 0%, #1a1f36 100%);
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            padding: 1rem 0;
        }
        
        .navbar-brand {
            font-weight: bold;
            font-size: 1.5rem;
            color: white !important;
        }
        
        .navbar-nav .nav-link {
            color: rgba(255,255,255,0.8) !important;
            margin: 0 0.5rem;
            transition: all 0.3s ease;
            border-radius: 4px;
        }
        
        .navbar-nav .nav-link:hover {
            color: white !important;
            background: rgba(255,255,255,0.1);
        }
        
        .navbar-nav .nav-link.active {
            background: var(--secondary-color);
            color: white !important;
        }
        
        /* Container */
        .container {
            max-width: 1400px;
            margin-top: 2rem;
            margin-bottom: 2rem;
        }
        
        /* Page Header */
        .page-header {
            background: white;
            padding: 2rem;
            border-radius: 8px;
            margin-bottom: 2rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .page-header h1 {
            margin: 0;
            color: var(--primary-color);
            font-weight: 600;
        }
        
        .page-header .btn-group {
            display: flex;
            gap: 0.5rem;
        }
        
        /* Cards */
        .card {
            border: none;
            box-shadow: 0 1px 4px rgba(0,0,0,0.08);
            border-radius: 8px;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        
        .card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.12);
        }
        
        /* Buttons */
        .btn-primary {
            background: var(--secondary-color);
            border: none;
            border-radius: 6px;
            padding: 0.5rem 1.2rem;
            font-weight: 500;
            transition: all 0.3s ease;
        }
        
        .btn-primary:hover {
            background: #2980b9;
            transform: translateY(-1px);
            box-shadow: 0 4px 8px rgba(52, 152, 219, 0.3);
        }
        
        .btn-sm {
            padding: 0.35rem 0.75rem;
            font-size: 0.875rem;
        }
        
        /* Tables */
        .table {
            background: white;
            border-radius: 8px;
            overflow: hidden;
        }
        
        .table thead th {
            background: var(--primary-color);
            color: white;
            border: none;
            font-weight: 600;
            padding: 1rem;
        }
        
        .table tbody td {
            vertical-align: middle;
            padding: 1rem;
            border-color: #ecf0f1;
        }
        
        .table tbody tr {
            transition: background 0.2s;
        }
        
        .table tbody tr:hover {
            background: #f8f9fa;
        }
        
        /* Status badges */
        .badge {
            padding: 0.4rem 0.8rem;
            border-radius: 6px;
            font-weight: 500;
            font-size: 0.85rem;
        }
        
        .badge-success {
            background: var(--success-color);
        }
        
        .badge-danger {
            background: var(--danger-color);
        }
        
        .badge-warning {
            background: var(--warning-color);
            color: white;
        }
        
        .badge-info {
            background: var(--secondary-color);
        }
        
        /* Footer */
        footer {
            background: var(--primary-color);
            color: white;
            text-align: center;
            padding: 2rem;
            margin-top: 3rem;
        }
    </style>
    
    @stack('styles')
</head>
<body>
    <!-- Navbar -->
    <nav class="navbar navbar-expand-lg navbar-dark">
        <div class="container-fluid">
            <a class="navbar-brand" href="{{ url('/admin/dashboard') }}">
                <i class="fas fa-chart-line"></i> Chambealo Admin
            </a>
            
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item">
                        <a class="nav-link {{ request()->routeIs('admin.payment-logs.*') ? 'active' : '' }}" 
                           href="{{ route('admin.payment-logs.index') }}">
                            <i class="fas fa-history"></i> Logs de Pagos
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="{{ url('/admin/dashboard') }}">
                            <i class="fas fa-tachometer-alt"></i> Dashboard
                        </a>
                    </li>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" id="userDropdown" role="button" data-bs-toggle="dropdown">
                            <i class="fas fa-user-circle"></i> {{ auth()->user()->full_name ?? auth()->user()->email }}
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><a class="dropdown-item" href="{{ url('/profile') }}">Perfil</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li>
                                <a class="dropdown-item" href="{{ route('logout') }}" 
                                   onclick="event.preventDefault(); document.getElementById('logout-form').submit();">
                                    Cerrar Sesión
                                </a>
                                <form id="logout-form" action="{{ route('logout') }}" method="POST" style="display: none;">
                                    @csrf
                                </form>
                            </li>
                        </ul>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <!-- Main Content -->
    <main class="container">
        @yield('content')
    </main>

    <!-- Footer -->
    <footer>
        <p>&copy; 2026 Chambealo. Sistema de Gestión de Pagos</p>
    </footer>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    @stack('scripts')
</body>
</html>
