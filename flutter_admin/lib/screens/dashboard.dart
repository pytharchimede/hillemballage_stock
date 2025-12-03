import 'package:flutter/material.dart';
import '../api.dart';

class DashboardScreen extends StatefulWidget {
  const DashboardScreen({super.key});

  @override
  State<DashboardScreen> createState() => _DashboardScreenState();
}

class _DashboardScreenState extends State<DashboardScreen> {
  bool _loading = true;

  int _clientsCount = 0;
  int _productsCount = 0;
  int _depotsCount = 0;
  int _livreursCount = 0; // TODO: brancher endpoint livreurs

  double _ventesDuJour = 0;
  double _encaissementsDuJour = 0;
  int _tourneesEnCours = 0;
  int _commandesDuJour = 0;

  Map<String, dynamic>? _me;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final me = await Api.me();
      final clients = await Api.clients();
      final products = await Api.products();
      final depots = await Api.depots();
      // Best-effort endpoints
      final ventes = await Api.salesToday();
      final encaiss = await Api.paymentsToday();
      final tournees = await Api.roundsOngoingCount();
      final commandes = await Api.ordersTodayCount();

      setState(() {
        _me = me;
        _clientsCount = clients.length;
        _productsCount = products.length;
        _depotsCount = depots.length;
        _ventesDuJour = (ventes ?? 0).toDouble();
        _encaissementsDuJour = (encaiss ?? 0).toDouble();
        _tourneesEnCours = (tournees ?? 0);
        _commandesDuJour = (commandes ?? 0);
        _loading = false;
      });
    } catch (e) {
      setState(() {
        _loading = false;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final name = _displayName(_me);

    return Scaffold(
      appBar: AppBar(
        title: Text('Hill-Admin'),
        actions: [
          // Quick actions in a popup menu
          PopupMenuButton<int>(
            tooltip: 'Actions rapides',
            itemBuilder: (context) => [
              const PopupMenuItem(
                value: 1,
                child: ListTile(
                    leading: Icon(Icons.add_shopping_cart),
                    title: Text('Nouvelle commande')),
              ),
              const PopupMenuItem(
                value: 2,
                child: ListTile(
                    leading: Icon(Icons.route), title: Text('Voir tournées')),
              ),
              const PopupMenuItem(
                value: 3,
                child: ListTile(
                    leading: Icon(Icons.inventory), title: Text('Produits')),
              ),
            ],
            onSelected: (value) {
              switch (value) {
                case 1:
                  Navigator.pushNamed(context, '/orders');
                  break;
                case 2:
                  Navigator.pushNamed(context, '/rounds');
                  break;
                case 3:
                  Navigator.pushNamed(context, '/products');
                  break;
              }
            },
          ),
          IconButton(
            tooltip: 'Rafraîchir',
            onPressed: _load,
            icon: const Icon(Icons.refresh),
          ),
        ],
      ),
      body: CustomScrollView(
        slivers: [
          SliverToBoxAdapter(
            child: Padding(
              padding: const EdgeInsets.fromLTRB(16, 16, 16, 8),
              child: Row(
                children: [
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          'Bonjour $name',
                          style: theme.textTheme.titleLarge,
                          overflow: TextOverflow.ellipsis,
                        ),
                        const SizedBox(height: 4),
                        Text(
                          'Vue d’ensemble',
                          style: theme.textTheme.bodyMedium?.copyWith(
                              color:
                                  theme.colorScheme.onSurface.withOpacity(0.7)),
                        ),
                      ],
                    ),
                  ),
                ],
              ),
            ),
          ),
          SliverPadding(
            padding: const EdgeInsets.all(16),
            sliver: SliverGrid(
              gridDelegate: const SliverGridDelegateWithFixedCrossAxisCount(
                crossAxisCount: 2,
                mainAxisSpacing: 12,
                crossAxisSpacing: 12,
                childAspectRatio: 1.3,
              ),
              delegate: SliverChildListDelegate.fixed([
                _StatCard(
                  icon: Icons.people,
                  title: 'Clients',
                  value: _clientsCount.toString(),
                  trend: '+',
                ),
                _StatCard(
                  icon: Icons.inventory_2,
                  title: 'Produits',
                  value: _productsCount.toString(),
                  trend: '+',
                ),
                _StatCard(
                  icon: Icons.home_work,
                  title: 'Depots',
                  value: _depotsCount.toString(),
                  trend: '+',
                ),
                _StatCard(
                  icon: Icons.delivery_dining,
                  title: 'Livreurs',
                  value: _livreursCount.toString(),
                  trend: '+',
                ),
                _StatCard(
                  icon: Icons.shopping_bag,
                  title: 'Commandes',
                  value: _commandesDuJour.toString(),
                  trend: _commandesDuJour > 0 ? '+ aujourd’hui' : '—',
                ),
                _StatCard(
                  icon: Icons.route,
                  title: 'Tournées',
                  value: _tourneesEnCours.toString(),
                  trend: _tourneesEnCours > 0 ? 'en cours' : '—',
                ),
                _StatCard(
                  icon: Icons.attach_money,
                  title: 'Ventes',
                  value: _formatMoney(_ventesDuJour),
                  trend: 'jour',
                ),
                _StatCard(
                  icon: Icons.payments,
                  title: 'Encaissements',
                  value: _formatMoney(_encaissementsDuJour),
                  trend: 'jour',
                ),
              ]),
            ),
          ),
          if (_loading)
            const SliverToBoxAdapter(
              child: Padding(
                padding: EdgeInsets.all(16),
                child: Center(child: CircularProgressIndicator()),
              ),
            ),
        ],
      ),
    );
  }

  String _formatMoney(double value) {
    return '${value.toStringAsFixed(0)} CFA';
  }
}

class _StatCard extends StatelessWidget {
  const _StatCard({
    required this.icon,
    required this.title,
    required this.value,
    required this.trend,
  });

  final IconData icon;
  final String title;
  final String value;
  final String trend;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final isDark = theme.brightness == Brightness.dark;
    final bg = isDark ? theme.colorScheme.surfaceVariant : Colors.white;
    final border =
        BorderSide(color: theme.colorScheme.outlineVariant.withOpacity(0.4));

    return Container(
      decoration: BoxDecoration(
        color: bg,
        borderRadius: BorderRadius.circular(16),
        border: Border.fromBorderSide(border),
        boxShadow: [
          if (!isDark)
            BoxShadow(
              color: Colors.black.withOpacity(0.04),
              blurRadius: 10,
              offset: const Offset(0, 6),
            ),
        ],
      ),
      padding: const EdgeInsets.all(16),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          _IconBadge(icon: icon),
          const SizedBox(height: 14),
          Text(title, style: theme.textTheme.bodyMedium),
          const SizedBox(height: 6),
          Text(
            value,
            style: theme.textTheme.headlineSmall
                ?.copyWith(fontSize: 20, fontWeight: FontWeight.w700),
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
          ),
          const Spacer(),
          Text(
            trend,
            style: theme.textTheme.labelMedium
                ?.copyWith(color: theme.colorScheme.primary),
          ),
        ],
      ),
    );
  }
}

class _IconBadge extends StatelessWidget {
  const _IconBadge({required this.icon});

  final IconData icon;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final isDark = theme.brightness == Brightness.dark;
    final bg = isDark
        ? theme.colorScheme.primary.withOpacity(0.12)
        : theme.colorScheme.primary.withOpacity(0.1);
    final fg = theme.colorScheme.primary;

    return Container(
      decoration: BoxDecoration(
        color: bg,
        borderRadius: BorderRadius.circular(12),
      ),
      padding: const EdgeInsets.all(10),
      child: Icon(icon, color: fg, size: 24),
    );
  }
}

String _displayName(Map<String, dynamic>? me) {
  final full = me?['name']?.toString();
  final email = me?['email']?.toString();
  if (full != null && full.trim().isNotEmpty) {
    final parts = full.trim().split(' ');
    return parts.first;
  }
  if (email != null && email.contains('@')) {
    return email.split('@').first;
  }
  return 'Admin';
}
