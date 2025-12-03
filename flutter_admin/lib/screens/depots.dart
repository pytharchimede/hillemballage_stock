import 'package:flutter/material.dart';
import '../widgets/admin_scaffold.dart';

class DepotsScreen extends StatelessWidget {
  const DepotsScreen({super.key});

  @override
  Widget build(BuildContext context) {
    return AdminScaffold(
      title: 'Dépôts',
      currentRoute: '/depots',
      body: const Center(child: Text('Dépôts — à implémenter')),
    );
  }
}
