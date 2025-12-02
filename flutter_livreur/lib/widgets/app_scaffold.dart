import 'package:flutter/material.dart';
import '../api.dart';

class AppScaffold extends StatelessWidget {
  final String title;
  final Widget body;
  final String currentRoute;
  final List<Widget>? actions;

  const AppScaffold({
    super.key,
    required this.title,
    required this.body,
    required this.currentRoute,
    this.actions,
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
                errorBuilder: (c, e, s) => const Icon(Icons.store, size: 22),
                fit: BoxFit.contain,
              ),
            ),
            const SizedBox(width: 8),
            Text(title),
          ],
        ),
        actions: actions,
      ),
      drawer: _AppDrawer(currentRoute: currentRoute),
      body: body,
    );
  }
}

class _AppDrawer extends StatelessWidget {
  final String currentRoute;
  const _AppDrawer({required this.currentRoute});

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
                        errorBuilder: (c, e, s) => Container(
                          color: Colors.white,
                          alignment: Alignment.center,
                          child: const Icon(Icons.inventory_2, size: 24),
                        ),
                        fit: BoxFit.contain,
                      ),
                    ),
                  ),
                  const SizedBox(width: 12),
                  const Expanded(
                    child: Text(
                      'Hillembalage',
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
            ListTile(
              leading: const Icon(Icons.dashboard_outlined),
              title: const Text('Dashboard'),
              selected: currentRoute == '/dashboard',
              onTap: () {
                if (currentRoute != '/dashboard') {
                  Navigator.of(context).pushReplacementNamed('/dashboard');
                } else {
                  Navigator.pop(context);
                }
              },
            ),
            ListTile(
              leading: const Icon(Icons.point_of_sale_outlined),
              title: const Text('Vente rapide'),
              selected: currentRoute == '/sales_quick' ||
                  currentRoute == '/sales_quick_entry',
              onTap: () {
                if (currentRoute != '/sales_quick_entry') {
                  Navigator.of(context)
                      .pushReplacementNamed('/sales_quick_entry');
                } else {
                  Navigator.pop(context);
                }
              },
            ),
            const Spacer(),
            const Divider(height: 1),
            ListTile(
              leading: const Icon(Icons.logout),
              title: const Text('Déconnexion'),
              onTap: () {
                // Clear token (non bloquant) puis naviguer
                Api.logout();
                Navigator.of(context)
                    .pushNamedAndRemoveUntil('/login', (r) => false);
              },
            ),
          ],
        ),
      ),
    );
  }
}
