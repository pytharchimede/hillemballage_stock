import 'package:flutter/material.dart';
import '../widgets/admin_scaffold.dart';

class RoundsScreen extends StatelessWidget {
  const RoundsScreen({super.key});

  @override
  Widget build(BuildContext context) {
    return AdminScaffold(
      title: 'Tournées',
      currentRoute: '/rounds',
      body: const Center(child: Text('Tournées — à implémenter')),
    );
  }
}
