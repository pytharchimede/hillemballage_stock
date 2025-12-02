import 'package:flutter/material.dart';
import '../api.dart';
import '../widgets/app_scaffold.dart';

class SalesQuickEntryScreen extends StatefulWidget {
  const SalesQuickEntryScreen({super.key});

  @override
  State<SalesQuickEntryScreen> createState() => _SalesQuickEntryScreenState();
}

class _SalesQuickEntryScreenState extends State<SalesQuickEntryScreen> {
  bool _loading = true;
  List<dynamic> _rounds = [];
  String? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final me = await Api.me();
      if (me == null) {
        if (!mounted) return;
        Navigator.of(context).pushNamedAndRemoveUntil('/login', (r) => false);
        return;
      }
      final rounds = await Api.openRounds(userId: me['id'] as int?);
      if (!mounted) return;
      setState(() {
        _rounds = rounds;
        _loading = false;
      });
      if (rounds.isEmpty) return;
      if (rounds.length == 1) {
        Navigator.of(context)
            .pushReplacementNamed('/sales_quick', arguments: rounds.first);
      }
    } catch (e) {
      setState(() {
        _error = e.toString();
        _loading = false;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    return AppScaffold(
      title: 'Vente rapide',
      currentRoute: '/sales_quick_entry',
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : Padding(
              padding: const EdgeInsets.all(12),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  if (_error != null)
                    Text(_error!, style: const TextStyle(color: Colors.red)),
                  if (_rounds.isEmpty) const Text('Aucune tournée ouverte'),
                  if (_rounds.isNotEmpty)
                    Expanded(
                      child: ListView.separated(
                        itemCount: _rounds.length,
                        separatorBuilder: (_, __) => const Divider(height: 1),
                        itemBuilder: (c, i) {
                          final r = _rounds[i] as Map<String, dynamic>;
                          return ListTile(
                            leading: const Icon(Icons.directions_run),
                            title: Text('Tournée #${r['id']}'),
                            subtitle: Text(
                                'Dépôt: ${r['depot_name'] ?? r['depot_id']}'),
                            trailing: const Icon(Icons.chevron_right),
                            onTap: () => Navigator.of(context)
                                .pushReplacementNamed('/sales_quick',
                                    arguments: r),
                          );
                        },
                      ),
                    ),
                ],
              ),
            ),
    );
  }
}
