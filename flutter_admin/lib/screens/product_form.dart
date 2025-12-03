import 'package:flutter/material.dart';
import '../widgets/admin_scaffold.dart';
import '../api.dart';
import 'package:image_picker/image_picker.dart';

class ProductFormScreen extends StatefulWidget {
  const ProductFormScreen({super.key});

  @override
  State<ProductFormScreen> createState() => _ProductFormScreenState();
}

class _ProductFormScreenState extends State<ProductFormScreen> {
  final _formKey = GlobalKey<FormState>();
  final _nameCtrl = TextEditingController();
  final _skuCtrl = TextEditingController();
  final _priceCtrl = TextEditingController();
  bool _active = true;
  int? _id; // if edit
  XFile? _pickedImage;
  bool _clearImage = false;
  String? _existingImagePath;

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    final args = ModalRoute.of(context)?.settings.arguments;
    if (args is Map<String, dynamic> && _id == null) {
      _id = (args['id'] as num?)?.toInt();
      _nameCtrl.text = (args['name'] ?? '') as String;
      _skuCtrl.text = (args['sku'] ?? '') as String;
      final up = args['unit_price'];
      if (up != null) _priceCtrl.text = '$up';
      final a = args['active'];
      if (a != null) _active = '$a' != '0';
      final img = args['image_path'];
      if (img is String && img.isNotEmpty) _existingImagePath = img;
      setState(() {});
    }
  }

  Future<void> _pickImage() async {
    final picker = ImagePicker();
    final x =
        await picker.pickImage(source: ImageSource.gallery, imageQuality: 85);
    if (x != null) {
      setState(() {
        _pickedImage = x;
        _clearImage = false;
      });
    }
  }

  Future<void> _save() async {
    if (!_formKey.currentState!.validate()) return;
    final name = _nameCtrl.text.trim();
    final sku = _skuCtrl.text.trim().isEmpty ? null : _skuCtrl.text.trim();
    final unitPrice = _priceCtrl.text.trim().isEmpty
        ? null
        : num.tryParse(_priceCtrl.text.trim());
    if (_id == null) {
      final res = await Api.createProduct(
        name: name,
        sku: sku,
        unitPrice: unitPrice,
        active: _active,
        image: _pickedImage,
      );
      if (!mounted) return;
      if (res != null) {
        ScaffoldMessenger.of(context)
            .showSnackBar(const SnackBar(content: Text('Produit créé')));
        Navigator.of(context).pop(true);
      } else {
        ScaffoldMessenger.of(context).showSnackBar(
            const SnackBar(content: Text('Échec de la création')));
      }
    } else {
      final res = await Api.updateProduct(
        id: _id!,
        name: name,
        sku: sku,
        unitPrice: unitPrice,
        active: _active,
        image: _pickedImage,
        clearImage: _clearImage,
      );
      if (!mounted) return;
      if (res != null) {
        ScaffoldMessenger.of(context)
            .showSnackBar(const SnackBar(content: Text('Produit enregistré')));
        Navigator.of(context).pop(true);
      } else {
        ScaffoldMessenger.of(context).showSnackBar(
            const SnackBar(content: Text('Échec de la mise à jour')));
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    return AdminScaffold(
      title: _id == null ? 'Nouveau produit' : 'Modifier produit',
      currentRoute: '/products',
      body: Padding(
        padding: const EdgeInsets.all(12.0),
        child: Form(
          key: _formKey,
          child: ListView(
            children: [
              // Image preview + actions
              Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Container(
                    width: 96,
                    height: 96,
                    decoration: BoxDecoration(
                      border: Border.all(color: Colors.black12),
                      borderRadius: BorderRadius.circular(8),
                    ),
                    clipBehavior: Clip.antiAlias,
                    child: _pickedImage != null
                        ? FutureBuilder<Widget>(
                            future: _buildPickedPreview(),
                            builder: (ctx, snap) {
                              if (snap.hasData) return snap.data!;
                              return const Center(
                                  child: CircularProgressIndicator(
                                      strokeWidth: 2));
                            },
                          )
                        : (_existingImagePath != null && !_clearImage)
                            ? Image.network(
                                Api.resolveImageUrl(_existingImagePath),
                                fit: BoxFit.cover,
                              )
                            : const Center(
                                child:
                                    Icon(Icons.image_not_supported_outlined)),
                  ),
                  const SizedBox(width: 12),
                  Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      ElevatedButton.icon(
                        onPressed: _pickImage,
                        icon: const Icon(Icons.photo_outlined),
                        label: const Text('Choisir une image'),
                      ),
                      const SizedBox(height: 6),
                      if (_pickedImage != null ||
                          (_existingImagePath != null && !_clearImage))
                        OutlinedButton.icon(
                          onPressed: () => setState(() {
                            _pickedImage = null;
                            _clearImage = true;
                          }),
                          icon: const Icon(Icons.delete_outline),
                          label: const Text('Retirer l\'image'),
                        ),
                    ],
                  ),
                ],
              ),
              const SizedBox(height: 12),
              TextFormField(
                controller: _nameCtrl,
                decoration: const InputDecoration(
                  labelText: 'Nom',
                  border: OutlineInputBorder(),
                ),
                validator: (v) =>
                    (v == null || v.trim().isEmpty) ? 'Nom requis' : null,
              ),
              const SizedBox(height: 12),
              TextFormField(
                controller: _skuCtrl,
                decoration: const InputDecoration(
                  labelText: 'SKU',
                  border: OutlineInputBorder(),
                ),
              ),
              const SizedBox(height: 12),
              TextFormField(
                controller: _priceCtrl,
                keyboardType: TextInputType.number,
                decoration: const InputDecoration(
                  labelText: 'Prix unitaire (FCFA)',
                  border: OutlineInputBorder(),
                ),
              ),
              const SizedBox(height: 12),
              SwitchListTile(
                title: const Text('Actif'),
                value: _active,
                onChanged: (v) => setState(() => _active = v),
              ),
              const SizedBox(height: 16),
              Row(
                children: [
                  ElevatedButton.icon(
                    onPressed: _save,
                    icon: const Icon(Icons.save_outlined),
                    label: const Text('Enregistrer'),
                  ),
                  const SizedBox(width: 8),
                  OutlinedButton(
                    onPressed: () => Navigator.of(context).pop(),
                    child: const Text('Annuler'),
                  ),
                ],
              ),
            ],
          ),
        ),
      ),
    );
  }

  Future<Widget> _buildPickedPreview() async {
    if (_pickedImage == null) return const SizedBox();
    final bytes = await _pickedImage!.readAsBytes();
    return Image.memory(bytes, fit: BoxFit.cover);
  }

  @override
  void dispose() {
    _nameCtrl.dispose();
    _skuCtrl.dispose();
    _priceCtrl.dispose();
    super.dispose();
  }
}
