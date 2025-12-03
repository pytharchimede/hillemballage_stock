import 'package:flutter/material.dart';
import '../widgets/admin_scaffold.dart';

class ReportsScreen extends StatelessWidget {
  const ReportsScreen({super.key});

  @override
  Widget build(BuildContext context) {
    return AdminScaffold(
      title: 'Rapports',
      currentRoute: '/reports',
      body: const Center(child: Text('Rapports — à implémenter')),
    );
  }
}
