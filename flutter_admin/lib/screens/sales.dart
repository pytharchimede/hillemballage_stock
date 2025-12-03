import 'package:flutter/material.dart';
import '../widgets/admin_scaffold.dart';

class SalesScreen extends StatelessWidget {
  const SalesScreen({super.key});

  @override
  Widget build(BuildContext context) {
    return AdminScaffold(
      title: 'Ventes',
      currentRoute: '/sales',
      body: const Center(child: Text('Ventes — à implémenter')),
    );
  }
}
