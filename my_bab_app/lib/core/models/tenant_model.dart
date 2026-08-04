class TenantModel {
  final int id;
  final String name;
  final String slug;
  final String? domain;
  final String status;

  TenantModel({
    required this.id,
    required this.name,
    required this.slug,
    this.domain,
    required this.status,
  });

  factory TenantModel.fromJson(Map<String, dynamic> json) {
    return TenantModel(
      id: json['id'],
      name: json['name'] ?? '',
      slug: json['slug'] ?? '',
      domain: json['domain'],
      status: json['status'] ?? 'active',
    );
  }

  Map<String, dynamic> toJson() => {
        'id': id,
        'name': name,
        'slug': slug,
        'domain': domain,
        'status': status,
      };
}
