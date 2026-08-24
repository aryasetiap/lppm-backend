<?php

$structure = '/images/sub-bagian/pusat-lppm/struktur-organisasi.png';

$center = static function (
    string $name,
    string $shortName,
    string $leader,
    string $photo,
    string $task,
    array $functions
) use ($structure): array {
    return [
        'nama' => $name,
        'singkatan' => $shortName,
        'kategori' => 'Pusat LPPM',
        'pimpinan' => [
            'ketua' => [
                'nama' => $leader,
                'foto' => $photo,
                'placeholder' => 'https://via.placeholder.com/400x400/105091/ffffff?text=Kepala+Pusat+LPPM',
                'jabatan' => 'Kepala '.$name,
                'periode' => '',
            ],
        ],
        'profile_singkat' => $task,
        'profil' => [
            'visi' => '',
            'misi' => [],
            'program_unggulan' => [],
            'prestasi' => [],
            'keunggulan' => [],
        ],
        'tugas_fungsi' => $functions,
        'struktur_organisasi' => [
            'gambar_struktur' => $structure,
            'gambar_placeholder' => 'https://via.placeholder.com/1200x800/f0f9ff/105091?text=Struktur+Organisasi+Pusat+LPPM',
        ],
    ];
};

return [
    'metadata' => [
        'last_updated' => '2026-08-06',
        'data_source' => 'Dokumen UPDATE WEBSITE versi 2',
        'description' => 'Data Pusat LPPM Universitas Lampung sesuai pembaruan organisasi versi 2.',
    ],
    'centers' => [
        'pusat-unggulan-anggrek' => $center(
            'Pusat Unggulan Anggrek',
            'Pusat Anggrek',
            'Prof. Dr. Ir. Yusnita, M.Sc.',
            '/images/sub-bagian/pusat-lppm/anggrek.jpeg',
            'Pusat Unggulan Anggrek mempunyai tugas melaksanakan riset dan pengembangan teknologi anggrek.',
            [
                'Menjadi pusat riset dan pengembangan teknologi budidaya dan pemuliaan anggrek.',
                'Mendorong komersialisasi produk berbasis bioteknologi anggrek.',
                'Mengembangkan kerja sama pengembangan teknologi budidaya dan pemuliaan anggrek.',
            ]
        ),
        'pusat-unggulan-komoditas-strategis' => $center(
            'Pusat Unggulan Komoditas Strategis',
            'Pusat Komoditas Strategis',
            'Dr. Dewi Sartika, S.T.P., M.Si.',
            '/images/sub-bagian/pusat-lppm/komoditas-strategis.jpeg',
            'Pusat Unggulan Komoditas Strategis mempunyai tugas melaksanakan riset dan pengembangan teknologi komoditas strategis agroindustri Lampung.',
            [
                'Menjadi pusat riset dan pengembangan teknologi budidaya dan pemuliaan komoditas tanaman strategis dan spesifik Provinsi Lampung.',
                'Mendorong komersialisasi produk berbasis bioteknologi komoditas tanaman spesifik Provinsi Lampung lainnya.',
                'Mengembangkan kerja sama dengan petani dan industri.',
                'Meneliti dan mengembangkan teknologi peningkatan produktivitas dan kualitas komoditas strategis seperti kopi, lada, kakao, tebu, dan sawit berbasis pertanian presisi.',
                'Mengembangkan inovasi teknologi pascapanen dan hilirisasi produk agroindustri untuk meningkatkan nilai tambah dan daya saing ekspor.',
                'Mengkaji dampak perubahan iklim dan volatilitas harga terhadap komoditas perkebunan serta merumuskan strategi mitigasi berbasis teknologi adaptif.',
                'Meningkatkan efisiensi rantai pasok dan distribusi produk agroindustri melalui digitalisasi dan pemanfaatan teknologi berbasis IoT, big data analytics, dan blockchain.',
                'Membangun kolaborasi strategis dengan petani, eksportir, industri, dan lembaga penelitian dalam penguatan standar produk berkelanjutan dan penerapan sertifikasi internasional.',
                'Mendukung pengembangan ekosistem agroindustri berbasis inovasi dan keberlanjutan, termasuk model pertanian regeneratif dan agroforestri yang berdampak positif bagi lingkungan dan ekonomi petani.',
            ]
        ),
        'pusat-cassava' => $center(
            'Pusat Cassava',
            'Pusat Cassava',
            'Dr. Agung Kusuma Wijaya, S.Pt., M.P.',
            '/images/sub-bagian/pusat-lppm/cassava.jpeg',
            'Pusat Cassava mempunyai tugas melaksanakan riset dan pengembangan teknologi cassava.',
            [
                'Menjadi pusat riset dan pengembangan teknologi perbenihan dan budidaya cassava.',
                'Mendorong komersialisasi produk berbasis cassava.',
                'Mengembangkan kerja sama pengembangan teknologi budidaya cassava.',
            ]
        ),
        'pusat-publikasi-ilmiah' => $center(
            'Pusat Publikasi Ilmiah',
            'Pusat Publikasi',
            'Ir. Gigih Forda Nama, S.T., M.T.I., IPM.',
            '/images/sub-bagian/pusat-lppm/publikasi-ilmiah.jpeg',
            'Pusat Publikasi Ilmiah mempunyai tugas melaksanakan pengelolaan publikasi ilmiah Universitas Lampung.',
            [
                'Meningkatkan kuantitas dan kualitas publikasi ilmiah yang bersumber dari riset unggulan Universitas Lampung.',
                'Mengelola layanan DOI dan TURNITIN untuk mendukung integritas publikasi ilmiah.',
                'Menyelenggarakan pendampingan penulisan dan penyuntingan artikel ilmiah bagi dosen dan peneliti.',
                'Mengelola jurnal ilmiah Universitas Lampung serta membangun jejaring dengan penerbit nasional dan internasional.',
                'Mendorong akses terbuka dan digitalisasi publikasi ilmiah.',
                'Mengembangkan pemanfaatan teknologi digital, termasuk AI, blockchain, dan IoT, untuk mendukung publikasi dan diseminasi hasil riset.',
                'Membangun kerja sama dengan industri dan pemangku kepentingan untuk mendukung publikasi berbasis kebutuhan Industri 4.0.',
                'Mengelola data SINTA untuk mendukung pemeringkatan dan peningkatan klaster LPPM secara nasional.',
            ]
        ),
        'pusat-data-dan-informasi' => $center(
            'Pusat Data dan Informasi',
            'Pusat Data dan Informasi',
            'Ir. Trisya Septiana, S.T., M.T., IPM.',
            '/images/sub-bagian/pusat-lppm/data-informasi.jpeg',
            'Pusat Data dan Informasi mempunyai tugas melaksanakan pengelolaan data dan informasi LPPM Universitas Lampung.',
            [
                'Menyediakan dan memperbarui data penelitian dan pengabdian kepada masyarakat.',
                'Melaksanakan digitalisasi pengelolaan data LPPM.',
                'Mengelola basis data penelitian, pengabdian, kekayaan intelektual, KKN, dan kegiatan LPPM lainnya.',
                'Menyusun strategi komunikasi dan penguatan citra LPPM.',
                'Mendukung pengelolaan sistem informasi, situs web, media sosial, dan platform digital LPPM.',
                'Menyebarluaskan informasi LPPM melalui media digital dan non-digital.',
                'Mengoordinasikan kebutuhan data dan informasi dengan unit kerja lain.',
            ]
        ),
        'pusat-hak-kekayaan-intelektual' => $center(
            'Pusat Hak Kekayaan Intelektual',
            'Pusat HKI',
            'Dr. Ade Arif Firmansyah, S.H., M.H.',
            '/images/sub-bagian/pusat-lppm/hak-kekayaan-intelektual.jpeg',
            'Pusat Hak Kekayaan Intelektual mempunyai tugas melaksanakan pengelolaan kekayaan intelektual Universitas Lampung.',
            [
                'Meningkatkan kesadaran sivitas akademika mengenai perlindungan kekayaan intelektual.',
                'Memberikan pendampingan pendaftaran dan perlindungan kekayaan intelektual.',
                'Membangun kerja sama dengan industri untuk komersialisasi kekayaan intelektual.',
                'Menyusun dan mengembangkan kebijakan pengelolaan kekayaan intelektual Universitas Lampung.',
            ]
        ),
        'pusat-kuliah-kerja-nyata' => $center(
            'Pusat Kuliah Kerja Nyata',
            'Pusat KKN',
            'Diky Hidayat, S.Si., M.Sc.',
            '/images/sub-bagian/pusat-lppm/kuliah-kerja-nyata.jpg',
            'Pusat Kuliah Kerja Nyata mempunyai tugas melaksanakan pengelolaan Kuliah Kerja Nyata Universitas Lampung.',
            [
                'Mengelola program KKN tematik berbasis riset dan kebutuhan masyarakat.',
                'Meningkatkan dampak sosial dan ekonomi melalui program KKN berbasis pemberdayaan.',
                'Menjalin kolaborasi dengan pemerintah dan sektor swasta dalam implementasi program.',
                'Melaksanakan program KKN Nasional, KKN Kebangsaan, KKN internasional, dan program KKN lainnya.',
            ]
        ),
        'pusat-halal' => $center(
            'Pusat Halal',
            'Pusat Halal',
            'Gita Paramita Djausal, S.IP., M.B.A.',
            '/images/sub-bagian/pusat-lppm/halal.png',
            'Pusat Halal mempunyai tugas melaksanakan penyediaan, pelayanan kajian, dan pengelolaan halal.',
            [
                'Memberikan pelayanan kepada pelaku usaha dan pemerintah dalam bidang jaminan produk halal.',
                'Melaksanakan kajian ilmiah mengenai jaminan produk halal.',
                'Menyelenggarakan pelatihan auditor halal, penyelia halal, dan pendamping proses produk halal.',
                'Menyelenggarakan lokakarya, seminar, sertifikasi, dan literasi halal.',
                'Membangun kerja sama dengan pemerintah, industri, BPJPH, lembaga pemeriksa dan sertifikasi halal, serta perguruan tinggi.',
            ]
        ),
        'pusat-inkubator-bisnis-inovasi-dan-hilirisasi' => $center(
            'Pusat Inkubator Bisnis, Inovasi, dan Hilirisasi',
            'Pusat Inkubator Bisnis',
            'Fajrin Satria Dwi Kesumah, S.E., FBE.',
            '/images/sub-bagian/pusat-lppm/inkubator-bisnis.jpeg',
            'Pusat Inkubator Bisnis, Inovasi, dan Hilirisasi mempunyai tugas melaksanakan pengelolaan inkubator bisnis dan hilirisasi inovasi Universitas Lampung.',
            [
                'Membangun ekosistem inovasi dan kewirausahaan di lingkungan Universitas Lampung.',
                'Mendampingi mahasiswa dan dosen dalam proses inkubasi usaha dan pengembangan startup.',
                'Mendorong hilirisasi hasil riset dan inovasi ke industri dan pasar.',
                'Menyediakan layanan mentoring, jejaring, dan akses permodalan bagi tenant.',
                'Mengembangkan ekonomi kreatif, inovasi sosial, dan diversifikasi produk berbasis potensi lokal.',
                'Membangun kemitraan untuk pengembangan ekonomi kreatif dan desa unggulan.',
                'Mengembangkan teknologi yang mendukung kemandirian energi.',
                'Membangun kolaborasi dengan petani dan UMKM dalam penerapan inovasi.',
            ]
        ),
        'pusat-sustainable-development-goals' => $center(
            'Pusat Sustainable Development Goals (SDGs)',
            'Pusat SDGs',
            'Dr. Henky Mayaquezz, S.Pi., M.T., M.Sc.',
            '/images/sub-bagian/pusat-lppm/sdgs.jpg',
            'Pusat Sustainable Development Goals mempunyai tugas melaksanakan riset dan pengelolaan SDGs Universitas Lampung.',
            [
                'Mengembangkan proyek sosial dan teknologi hijau yang mendukung pencapaian SDGs.',
                'Melaksanakan riset terkait pencapaian indikator SDGs.',
                'Mengintegrasikan SDGs dalam kurikulum, penelitian, dan pengabdian kepada masyarakat.',
                'Memantau dan memperbarui data UI GreenMetric Universitas Lampung.',
                'Melaksanakan advokasi kebijakan berbasis SDGs.',
                'Membangun kolaborasi nasional dan internasional dalam implementasi SDGs.',
            ]
        ),
        'pusat-pengembangan-riset-strategis' => $center(
            'Pusat Pengembangan Riset Strategis',
            'Pusat Riset Strategis',
            'Dr. Eng. Ir. Khairudin, S.T., M.Sc.',
            '/images/sub-bagian/pusat-lppm/riset-strategis.jpeg',
            'Pusat Pengembangan Riset Strategis mempunyai tugas melaksanakan riset dan pengembangan kajian strategis Universitas Lampung.',
            [
                'Melaksanakan riset kebijakan strategis pada bidang energi bersih, ekonomi hijau, teknologi digital, sosial budaya Lampung, hukum dan perlindungan perempuan dan anak, kemaritiman dan lingkungan, dana abadi, kesehatan, serta ketahanan pangan.',
                'Mengembangkan program riset strategis yang menjawab kebutuhan pembangunan daerah, nasional, dan global.',
                'Menginisiasi proyek riset multidisiplin pada isu strategis dan prioritas pembangunan.',
                'Membangun kolaborasi riset dengan pemerintah, industri, masyarakat, dan mitra nasional maupun internasional.',
            ]
        ),
        'pusat-pengabdian-dan-inovasi-sosial' => $center(
            'Pusat Pengabdian dan Inovasi Sosial',
            'Pusat Pengabdian dan Inovasi Sosial',
            'Dr. Ryzal Perdana, S.Pd., M.Pd.',
            '/images/sub-bagian/pusat-lppm/pengabdian-inovasi-sosial.jpeg',
            'Pusat Pengabdian dan Inovasi Sosial mempunyai tugas melaksanakan penguatan pengabdian strategis Universitas Lampung.',
            [
                'Melaksanakan pengabdian strategis berbasis teknologi, pendidikan, kesehatan, ketahanan pangan, dan pemberdayaan masyarakat.',
                'Mengembangkan program pengabdian dan inovasi sosial berdasarkan kebutuhan lokal dan tantangan global.',
                'Membangun kolaborasi pengabdian dengan pemerintah, industri, komunitas, dan mitra nasional maupun internasional.',
                'Menginisiasi model pengabdian berkelanjutan yang terukur dampaknya bagi masyarakat.',
            ]
        ),
        'pusat-digitalisasi-dan-kemitraan-global' => $center(
            'Pusat Digitalisasi dan Kemitraan Global',
            'Pusat Digitalisasi dan Kemitraan Global',
            'M. Iqbal Parabi, S.Si., M.T.',
            '/images/sub-bagian/pusat-lppm/digitalisasi-kemitraan-global.jpeg',
            'Pusat Digitalisasi dan Kemitraan Global mempunyai tugas melaksanakan pengelolaan sistem dan kemitraan strategis LPPM Universitas Lampung.',
            [
                'Mengembangkan sistem informasi terintegrasi dan kebijakan satu data, termasuk situs web, Silemlit21, media sosial, dan sistem plotting.',
                'Mengembangkan sistem internal dan eksternal untuk mendukung pengelolaan hibah kementerian dan industri.',
                'Membangun jejaring dan kemitraan penelitian serta pengabdian pada tingkat nasional dan internasional.',
                'Mengembangkan kemitraan strategis dengan pemerintah, industri, perguruan tinggi, dan organisasi internasional.',
            ]
        ),
    ],
];
