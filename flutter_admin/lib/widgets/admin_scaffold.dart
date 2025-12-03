import 'package:flutter/material.dart';
import '../api.dart';

class AdminScaffold extends StatelessWidget {
  final String title;
  final Widget body;
  final String currentRoute;
  final List<Widget>? actions;
  final Widget? floatingActionButton;

  const AdminScaffold({
    super.key,
    required this.title,
    required this.body,
    required this.currentRoute,
    this.actions,
    this.floatingActionButton,
  });

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: Row(
          children: [
            SizedBox(
              width: 28,
              height: 28,
              child: Image.asset(
                'assets/images/logo.png',
                errorBuilder: (c, e, s) =>
                    const Icon(Icons.inventory_2, size: 22),
                fit: BoxFit.contain,
              ),
            ),
            const SizedBox(width: 8),
            Text(title),
          ],
        ),
        actions: actions,
      ),
      drawer: _AdminDrawer(currentRoute: currentRoute),
      body: body,
      floatingActionButton: floatingActionButton,
    );
  }
}

class _AdminDrawer extends StatelessWidget {
  final String currentRoute;
  const _AdminDrawer({required this.currentRoute});

  @override
  Widget build(BuildContext context) {
    return Drawer(
      child: SafeArea(
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            DrawerHeader(
              margin: const EdgeInsets.only(bottom: 0),
              child: Row(
                children: [
                  ClipRRect(
                    borderRadius: BorderRadius.circular(8),
                    child: SizedBox(
                      width: 46,
                      height: 46,
                      child: Image.asset(
                        'assets/images/logo.png',
                        errorBuilder: (c, e, s) =>
                            const Icon(Icons.admin_panel_settings),
                        fit: BoxFit.contain,
                      ),
                    ),
                  ),
                  const SizedBox(width: 12),
                  const Expanded(
                    child: Text(
                      'Hillembalage Admin',
                      style: TextStyle(
                        fontWeight: FontWeight.w700,
                        fontSize: 16,
                      ),
                      overflow: TextOverflow.ellipsis,
                    ),
                  ),
                ],
              ),
            ),
            // Entrées principales
            ListTile(
              leading: const Icon(Icons.dashboard_outlined),
              title: const Text('Dashboard'),
              selected: currentRoute == '/dashboard',
              onTap: () {
                if (currentRoute != '/dashboard')
                  Navigator.of(context).pushReplacementNamed('/dashboard');
                else
                  Navigator.pop(context);
              },
            ),
            ListTile(
              leading: const Icon(Icons.inventory_2_outlined),
              title: const Text('Produits'),
              selected: currentRoute == '/products',
              onTap: () {
                if (currentRoute != '/products')
                  Navigator.of(context).pushReplacementNamed('/products');
                else
                  Navigator.pop(context);
              },
            ),
            ListTile(
              leading: const Icon(Icons.people_alt_outlined),
              title: const Text('Clients'),
              selected: currentRoute == '/clients',
              onTap: () {
                if (currentRoute != '/clients')
                  Navigator.of(context).pushReplacementNamed('/clients');
                else
                  Navigator.pop(context);
              },
            ),
            ListTile(
              leading: const Icon(Icons.storefront_outlined),
              title: const Text('Dépôts'),
              selected: currentRoute == '/depots',
              onTap: () {
                if (currentRoute != '/depots')
                  Navigator.of(context).pushReplacementNamed('/depots');
                else
                  Navigator.pop(context);
              },
            ),
            ListTile(
              leading: const Icon(Icons.receipt_long_outlined),
              title: const Text('Ventes'),
              selected: currentRoute == '/sales',
              onTap: () {
                if (currentRoute != '/sales')
                  Navigator.of(context).pushReplacementNamed('/sales');
                else
                  Navigator.pop(context);
              },
            ),
            ListTile(
              leading: const Icon(Icons.map_outlined),
              title: const Text('Tournées'),
              selected: currentRoute == '/rounds',
              onTap: () {
                if (currentRoute != '/rounds')
                  Navigator.of(context).pushReplacementNamed('/rounds');
                else
                  Navigator.pop(context);
              },
            ),
            ListTile(
              leading: const Icon(Icons.analytics_outlined),
              title: const Text('Rapports'),
              selected: currentRoute == '/reports',
              onTap: () {
                if (currentRoute != '/reports')
                  Navigator.of(context).pushReplacementNamed('/reports');
                else
                  Navigator.pop(context);
              },
            ),
            const Spacer(),
            const Divider(height: 1),
            ListTile(
              leading: const Icon(Icons.logout),
              title: const Text('Déconnexion'),
              onTap: () {
                Api.clearToken();
                Navigator.of(
                  context,
                ).pushNamedAndRemoveUntil('/login', (r) => false);
              },
            ),
          ],
        ),
      ),
    );
  }
}
