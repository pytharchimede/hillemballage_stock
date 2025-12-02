import 'package:flutter/material.dart';
import '../api.dart';
import '../widgets/app_scaffold.dart';

class DashboardScreen extends StatefulWidget {
  const DashboardScreen({super.key});

  @override
  State<DashboardScreen> createState() => _DashboardScreenState();
}

class _DashboardScreenState extends State<DashboardScreen> {
  Map<String, dynamic>? _me;
  List<dynamic> _rounds = [];
  bool _loading = true;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    final me = await Api.me();
    if (me == null) {
      if (!mounted) return;
      Navigator.of(context).pushNamedAndRemoveUntil('/login', (r) => false);
      return;
    }
    final rounds = await Api.openRounds(userId: me['id'] as int?);
    setState(() {
      _me = me;
      _rounds = rounds;
      _loading = false;
    });
  }

  @override
  Widget build(BuildContext context) {
    return AppScaffold(
      title: 'Dashboard livreur',
      currentRoute: '/dashboard',
      actions: [
        IconButton(
          tooltip: 'Réglages API',
          icon: const Icon(Icons.settings),
          onPressed: () => Navigator.of(context).pushNamed('/settings'),
        ),
      ],
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : Padding(
              padding: const EdgeInsets.all(12),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    'Bonjour ${_me?['name'] ?? ''}',
                    style: Theme.of(context).textTheme.titleMedium,
                  ),
                  const SizedBox(height: 8),
                  if (_rounds.isEmpty) const Text('Aucune tournée ouverte'),
                  if (_rounds.isNotEmpty)
                    Card(
                      child: ListTile(
                        title: Text(
                          'Tournée #${_rounds.first['id']} — ${_rounds.first['depot_name'] ?? ''}',
                        ),
                        subtitle: Text(
                          'Assignée: ${_rounds.first['assigned_at'] ?? ''}',
                        ),
                        trailing: ElevatedButton(
                          onPressed: () => Navigator.of(
                            context,
                          ).pushNamed('/sales_quick', arguments: _rounds.first),
                          child: const Text('Vente rapide'),
                        ),
                      ),
                    ),
                ],
              ),
            ),
    );
  }
}
