import 'package:flutter/material.dart';
import '../api.dart';
import '../widgets/admin_scaffold.dart';

class DashboardScreen extends StatefulWidget {
  const DashboardScreen({super.key});

  @override
  State<DashboardScreen> createState() => _DashboardScreenState();
}

class _DashboardScreenState extends State<DashboardScreen> {
  bool _loading = true;
  String _period = 'week'; // 'day' | 'week'

  int _clientsCount = 0;
  int _productsCount = 0;
  int _depotsCount = 0;
  int _livreursCount = 0; // à brancher sur endpoint réel

  double _ventesDuJour = 0;
  double _encaissementsDuJour = 0;
  int _tourneesEnCours = 0;
  int _commandesDuJour = 0;

  Map<String, dynamic>? _me;

  // Séries temporelles (si disponibles depuis l'API)
  List<double>? _seriesClients;
  List<double>? _seriesProducts;
  List<double>? _seriesDepots;
  List<double>? _seriesLivreurs;
  List<double>? _seriesOrders;
  List<double>? _seriesRounds;
  List<double>? _seriesSales;
  List<double>? _seriesPayments;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final me = await Api.me();
      final stats = await Api.statsToday();
      final clients = await Api.clients();
      final products = await Api.products();
      final depots = await Api.depots();
      final ventes = await Api.salesToday();
      final encaiss = await Api.paymentsToday();
      final tournees = await Api.roundsOngoingCount();
      final commandes = await Api.ordersTodayCount();
      // Try time-series endpoints (graceful fallback to mocks in build)
      final seriesClients = await Api.seriesClients(period: _period);
      final seriesProducts = await Api.seriesProducts(period: _period);
      final seriesDepots = await Api.seriesDepots(period: _period);
      final seriesLivreurs = await Api.seriesLivreurs(period: _period);
      final seriesOrders =
          await Api.seriesOrders(period: _period == 'day' ? 'day' : 'week');
      final seriesRounds =
          await Api.seriesRounds(period: _period == 'day' ? 'day' : 'week');
      final seriesSales =
          await Api.seriesSales(period: _period == 'day' ? 'day' : 'week');
      final seriesPayments =
          await Api.seriesPayments(period: _period == 'day' ? 'day' : 'week');

      setState(() {
        _me = me;
        // Privilégier les vrais totaux si exposés par /stats, sinon fallback sur length
        _clientsCount = (stats['clients_count'] ??
                stats['clients'] ??
                clients.length) is num
            ? ((stats['clients_count'] ?? stats['clients']) as num).toInt()
            : clients.length;
        _productsCount = (stats['products_count'] ??
                stats['products'] ??
                products.length) is num
            ? ((stats['products_count'] ?? stats['products']) as num).toInt()
            : products.length;
        _depotsCount =
            (stats['depots_count'] ?? stats['depots'] ?? depots.length) is num
                ? ((stats['depots_count'] ?? stats['depots']) as num).toInt()
                : depots.length;
        _livreursCount = (stats['livreurs_count'] ??
                stats['drivers'] ??
                _livreursCount) is num
            ? ((stats['livreurs_count'] ?? stats['drivers']) as num).toInt()
            : _livreursCount;
        _ventesDuJour = (ventes ?? 0).toDouble();
        _encaissementsDuJour = (encaiss ?? 0).toDouble();
        _tourneesEnCours = (tournees ?? 0);
        _commandesDuJour = (commandes ?? 0);
        _seriesClients = seriesClients;
        _seriesProducts = seriesProducts;
        _seriesDepots = seriesDepots;
        _seriesLivreurs = seriesLivreurs;
        _seriesOrders = seriesOrders;
        _seriesRounds = seriesRounds;
        _seriesSales = seriesSales;
        _seriesPayments = seriesPayments;
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

    return AdminScaffold(
      title: 'Dashboard',
      currentRoute: '/dashboard',
      actions: [
        // Sélecteur de période jour/semaine
        // Bascule compacte période (icône + menu) pour éviter tout overflow
        PopupMenuButton<String>(
          tooltip: 'Période',
          icon: const Icon(Icons.timeline),
          onSelected: (v) {
            setState(() {
              _period = v;
              _loading = true;
            });
            _load();
          },
          itemBuilder: (context) => const [
            PopupMenuItem(value: 'day', child: Text('Jour')),
            PopupMenuItem(value: 'week', child: Text('Semaine')),
          ],
        ),
        PopupMenuButton<int>(
          tooltip: 'Actions rapides',
          itemBuilder: (context) => const [
            PopupMenuItem(
              value: 1,
              child: ListTile(
                  leading: Icon(Icons.add_shopping_cart),
                  title: Text('Nouvelle commande')),
            ),
            PopupMenuItem(
              value: 2,
              child: ListTile(
                  leading: Icon(Icons.route), title: Text('Voir tournées')),
            ),
            PopupMenuItem(
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
      body: CustomScrollView(
        slivers: [
          SliverToBoxAdapter(
            child: Padding(
              padding: const EdgeInsets.fromLTRB(16, 16, 16, 8),
              child: LayoutBuilder(
                builder: (context, constraints) {
                  final isNarrow = constraints.maxWidth < 360;
                  return Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        'Bonjour $name',
                        style: theme.textTheme.titleMedium?.copyWith(
                          color: theme.colorScheme.onSurface
                              .withValues(alpha: 0.85),
                          fontWeight: FontWeight.w600,
                        ),
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                      ),
                      const SizedBox(height: 4),
                      Text(
                        'Vue d’ensemble · ${_period == 'day' ? 'Jour' : 'Semaine'}',
                        style: theme.textTheme.bodyMedium?.copyWith(
                          color: theme.colorScheme.onSurface
                              .withValues(alpha: 0.7),
                          fontSize: isNarrow ? 12 : null,
                        ),
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                      ),
                    ],
                  );
                },
              ),
            ),
          ),
          SliverPadding(
            padding: const EdgeInsets.all(16),
            sliver: SliverGrid(
              gridDelegate: SliverGridDelegateWithFixedCrossAxisCount(
                // Breakpoints ajustés: 3 colonnes dès 900px
                crossAxisCount: () {
                  final w = MediaQuery.of(context).size.width;
                  if (w < 500) return 1; // téléphone étroit
                  if (w < 900) return 2; // 2 colonnes jusqu'à 900px
                  return 3; // large tablette / desktop
                }(),
                mainAxisSpacing: 6,
                crossAxisSpacing: 16,
                // Cartes compactes
                childAspectRatio: () {
                  final w = MediaQuery.of(context).size.width;
                  if (w < 500) return 1.7;
                  if (w < 900) return 2.35;
                  return 2.55;
                }(),
              ),
              delegate: SliverChildListDelegate.fixed([
                _StatCard(
                    icon: Icons.people,
                    title: 'Clients',
                    value: _clientsCount.toString(),
                    series: _seriesClients ??
                        [
                          (_clientsCount * 0.6).toDouble(),
                          (_clientsCount * 0.7).toDouble(),
                          (_clientsCount * 0.8).toDouble(),
                          (_clientsCount * 0.75).toDouble(),
                          (_clientsCount * 0.9).toDouble(),
                          _clientsCount.toDouble(),
                        ]),
                _StatCard(
                    icon: Icons.inventory_2,
                    title: 'Produits',
                    value: _productsCount.toString(),
                    series: _seriesProducts ??
                        [
                          (_productsCount * 0.5).toDouble(),
                          (_productsCount * 0.55).toDouble(),
                          (_productsCount * 0.6).toDouble(),
                          (_productsCount * 0.58).toDouble(),
                          (_productsCount * 0.62).toDouble(),
                          _productsCount.toDouble(),
                        ]),
                _StatCard(
                    icon: Icons.home_work,
                    title: 'Dépôts',
                    value: _depotsCount.toString(),
                    series: _seriesDepots ??
                        [
                          (_depotsCount * 0.8).toDouble(),
                          (_depotsCount * 0.7).toDouble(),
                          (_depotsCount * 0.9).toDouble(),
                          (_depotsCount * 0.85).toDouble(),
                          (_depotsCount * 0.95).toDouble(),
                          _depotsCount.toDouble(),
                        ]),
                _StatCard(
                    icon: Icons.delivery_dining,
                    title: 'Livreurs',
                    value: _livreursCount.toString(),
                    series: _seriesLivreurs ??
                        [
                          (_livreursCount * 0.6).toDouble(),
                          (_livreursCount * 0.65).toDouble(),
                          (_livreursCount * 0.7).toDouble(),
                          (_livreursCount * 0.68).toDouble(),
                          (_livreursCount * 0.72).toDouble(),
                          _livreursCount.toDouble(),
                        ]),
                _StatCard(
                  icon: Icons.shopping_bag,
                  title: 'Commandes',
                  value: _commandesDuJour.toString(),
                  trend: _commandesDuJour > 0 ? '+ aujourd’hui' : '—',
                  series: _seriesOrders ??
                      [
                        (_commandesDuJour * 0.3).toDouble(),
                        (_commandesDuJour * 0.5).toDouble(),
                        (_commandesDuJour * 0.4).toDouble(),
                        (_commandesDuJour * 0.7).toDouble(),
                        (_commandesDuJour * 0.8).toDouble(),
                        _commandesDuJour.toDouble(),
                      ],
                ),
                _StatCard(
                  icon: Icons.route,
                  title: 'Tournées',
                  value: _tourneesEnCours.toString(),
                  trend: _tourneesEnCours > 0 ? 'en cours' : '—',
                  series: _seriesRounds ??
                      [
                        (_tourneesEnCours * 0.2).toDouble(),
                        (_tourneesEnCours * 0.4).toDouble(),
                        (_tourneesEnCours * 0.3).toDouble(),
                        (_tourneesEnCours * 0.6).toDouble(),
                        (_tourneesEnCours * 0.9).toDouble(),
                        _tourneesEnCours.toDouble(),
                      ],
                ),
                _StatCard(
                    icon: Icons.attach_money,
                    title: 'Ventes',
                    value: _formatMoney(_ventesDuJour),
                    trend: 'du jour',
                    series: _seriesSales ??
                        [
                          _ventesDuJour * 0.3,
                          _ventesDuJour * 0.4,
                          _ventesDuJour * 0.5,
                          _ventesDuJour * 0.7,
                          _ventesDuJour * 0.8,
                          _ventesDuJour,
                        ]),
                _StatCard(
                    icon: Icons.payments,
                    title: 'Encaissements',
                    value: _formatMoney(_encaissementsDuJour),
                    trend: 'du jour',
                    series: _seriesPayments ??
                        [
                          _encaissementsDuJour * 0.25,
                          _encaissementsDuJour * 0.35,
                          _encaissementsDuJour * 0.45,
                          _encaissementsDuJour * 0.6,
                          _encaissementsDuJour * 0.9,
                          _encaissementsDuJour,
                        ]),
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
    this.trend,
    this.series,
  });

  final IconData icon;
  final String title;
  final String value;
  final String? trend;
  final List<double>? series;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final isDark = theme.brightness == Brightness.dark;

    return Container(
      decoration: BoxDecoration(
        color:
            isDark ? theme.colorScheme.surfaceContainerHighest : Colors.white,
        borderRadius: BorderRadius.circular(18),
        boxShadow: [
          if (!isDark)
            BoxShadow(
              color: const Color(0xFF000000).withValues(alpha: 0.05),
              offset: const Offset(0, 6),
              blurRadius: 16,
            ),
        ],
      ),
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),

      // IMPORTANT : la clé qui supprime 100% des overflow :
      child: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          _IconBadge(icon: icon),
          const SizedBox(height: 4),
          // Sparkline visible seulement si série valable et non totalement nulle
          if (series != null && series!.length >= 2)
            Builder(builder: (context) {
              final allZero = series!.every((v) => v.abs() < 1e-9);
              if (allZero) {
                return Padding(
                  padding: const EdgeInsets.only(top: 2),
                  child: SizedBox(
                    height: 20,
                    child: Align(
                      alignment: Alignment.centerLeft,
                      child: Text(
                        'Pas de données',
                        style: theme.textTheme.labelSmall?.copyWith(
                          color: theme.colorScheme.onSurface
                              .withValues(alpha: 0.55),
                        ),
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                      ),
                    ),
                  ),
                );
              }
              return Padding(
                padding: const EdgeInsets.only(top: 2),
                child: SizedBox(
                  height: 22,
                  child: _MiniSparkline(
                      values: series!, color: theme.colorScheme.primary),
                ),
              );
            }),

          // Titre
          Text(
            title,
            style: theme.textTheme.bodyMedium?.copyWith(
              fontWeight: FontWeight.w600,
              color: theme.colorScheme.onSurface.withValues(alpha: 0.85),
            ),
          ),
          const SizedBox(height: 2),
          FittedBox(
            fit: BoxFit.scaleDown,
            alignment: Alignment.centerLeft,
            child: Text(
              value,
              style: theme.textTheme.titleLarge?.copyWith(
                fontWeight: FontWeight.w700,
                fontSize: 18,
                color: theme.colorScheme.onSurface,
              ),
            ),
          ),

          const SizedBox(height: 4),

          if (trend != null && trend!.trim().isNotEmpty)
            Text(
              trend!,
              style: theme.textTheme.labelSmall?.copyWith(
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
        color: theme.colorScheme.primary.withValues(alpha: 0.12),
        borderRadius: BorderRadius.circular(12),
      ),
      child: Icon(icon, size: 24, color: theme.colorScheme.primary),
    );
  }
}

class _MiniSparkline extends StatelessWidget {
  const _MiniSparkline({required this.values, required this.color});

  final List<double> values;
  final Color color;

  @override
  Widget build(BuildContext context) {
    return CustomPaint(
      painter: _SparklinePainter(values: values, color: color),
    );
  }
}

class _SparklinePainter extends CustomPainter {
  _SparklinePainter({required this.values, required this.color});

  final List<double> values;
  final Color color;

  @override
  void paint(Canvas canvas, Size size) {
    if (values.isEmpty) return;

    // subtle grid background improves readability on flat lines
    final gridPaint = Paint()
      ..color = color.withValues(alpha: 0.06)
      ..style = PaintingStyle.stroke
      ..strokeWidth = 1;
    for (int i = 1; i <= 3; i++) {
      final y = size.height * (i / 4);
      canvas.drawLine(Offset(0, y), Offset(size.width, y), gridPaint);
    }

    final minV = values.reduce((a, b) => a < b ? a : b);
    final maxV = values.reduce((a, b) => a > b ? a : b);
    final range = (maxV - minV).abs() < 1e-6 ? 1 : (maxV - minV);

    final path = Path();
    for (var i = 0; i < values.length; i++) {
      final x = i / (values.length - 1) * size.width;
      final y = size.height - ((values[i] - minV) / range) * size.height;
      if (i == 0) {
        path.moveTo(x, y);
      } else {
        path.lineTo(x, y);
      }
    }

    final paint = Paint()
      ..color = color.withValues(alpha: 1.0)
      ..style = PaintingStyle.stroke
      ..strokeWidth = 2;

    canvas.drawPath(path, paint);

    // light fill under curve
    final fillPath = Path.from(path)
      ..lineTo(size.width, size.height)
      ..lineTo(0, size.height)
      ..close();
    final fillPaint = Paint()
      ..color = color.withValues(alpha: 0.08)
      ..style = PaintingStyle.fill;
    canvas.drawPath(fillPath, fillPaint);
  }

  @override
  bool shouldRepaint(covariant _SparklinePainter oldDelegate) {
    return oldDelegate.values != values || oldDelegate.color != color;
  }
}

String _displayName(Map<String, dynamic>? me) {
  final full = me?['name']?.toString();
  if (full != null && full.trim().isNotEmpty) {
    final parts = full.trim().split(' ');
    return parts.first;
  }
  return 'Admin';
}
