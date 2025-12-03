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
  int _livreursCount = 0;

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
      backgroundColor: theme.colorScheme.surface,
      appBar: AppBar(
        elevation: 0,
        backgroundColor: theme.colorScheme.surface,
        title: Text(
          'Hill-Admin',
          style: TextStyle(
            color: theme.colorScheme.onSurface,
            fontWeight: FontWeight.w700,
          ),
        ),
        actions: [
          PopupMenuButton<int>(
            tooltip: 'Actions rapides',
            icon: Icon(Icons.more_vert, color: theme.colorScheme.onSurface),
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
            icon: Icon(Icons.refresh, color: theme.colorScheme.onSurface),
          ),
        ],
      ),
      body: CustomScrollView(
        slivers: [
          // HEADER
          SliverToBoxAdapter(
            child: Padding(
              padding: const EdgeInsets.fromLTRB(16, 12, 16, 0),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    'Bonjour, $name',
                    style: theme.textTheme.headlineMedium?.copyWith(
                      fontWeight: FontWeight.w700,
                    ),
                  ),
                  const SizedBox(height: 4),
                  Text(
                    'Voici l’activité de votre journée',
                    style: theme.textTheme.bodyMedium?.copyWith(
                      color: theme.colorScheme.onSurface.withOpacity(0.7),
                    ),
                  ),
                  const SizedBox(height: 20),
                ],
              ),
            ),
          ),

          // GRID
          SliverPadding(
            padding: const EdgeInsets.symmetric(horizontal: 16),
            sliver: SliverGrid(
              gridDelegate: const SliverGridDelegateWithFixedCrossAxisCount(
                crossAxisCount: 2,
                mainAxisSpacing: 18,
                crossAxisSpacing: 18,
                childAspectRatio: .95,
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
                  title: 'Dépôts',
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
                  trend: 'du jour',
                ),
                _StatCard(
                  icon: Icons.payments,
                  title: 'Encaissements',
                  value: _formatMoney(_encaissementsDuJour),
                  trend: 'du jour',
                ),
              ]),
            ),
          ),

          if (_loading)
            const SliverToBoxAdapter(
              child: Padding(
                padding: EdgeInsets.all(24),
                child: Center(child: CircularProgressIndicator()),
              ),
            ),

          const SliverToBoxAdapter(child: SizedBox(height: 30)),
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

    return Container(
      decoration: BoxDecoration(
        color: isDark
            ? theme.colorScheme.surfaceContainerHigh
            : theme.colorScheme.surfaceVariant,
        borderRadius: BorderRadius.circular(18),
        boxShadow: [
          if (!isDark)
            BoxShadow(
              color: Colors.black.withOpacity(0.06),
              offset: const Offset(0, 6),
              blurRadius: 16,
            ),
        ],
      ),
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 14),

      // IMPORTANT : la clé qui supprime 100% des overflow :
      child: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          _IconBadge(icon: icon),
          const SizedBox(height: 12),

          // Titre
          Text(
            title,
            style: theme.textTheme.titleMedium?.copyWith(
              fontWeight: FontWeight.w600,
              color: theme.colorScheme.onSurface.withOpacity(0.85),
            ),
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
          ),
          const SizedBox(height: 6),

          // Valeur (optimisée pour ne jamais overflow)
          FittedBox(
            fit: BoxFit.scaleDown,
            alignment: Alignment.centerLeft,
            child: Text(
              value,
              style: theme.textTheme.headlineSmall?.copyWith(
                fontWeight: FontWeight.bold,
                fontSize: 22,
              ),
            ),
          ),

          const SizedBox(height: 10),

          // Trend
          Text(
            trend,
            style: theme.textTheme.labelMedium?.copyWith(
              color: theme.colorScheme.primary,
              fontWeight: FontWeight.w500,
            ),
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
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

    return Container(
      padding: const EdgeInsets.all(10),
      decoration: BoxDecoration(
        color: theme.colorScheme.primary.withOpacity(0.12),
        borderRadius: BorderRadius.circular(12),
      ),
      child: Icon(icon, size: 24, color: theme.colorScheme.primary),
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
