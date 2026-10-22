<?php
/** Conteúdo inicial migrado do HTML para section_items, para que possa ser editado no CMS. */
function defaultSectionItems(string $identifier): array {
    $data = [
        'sobre' => [
            ['card','Pesquisa','Investigação profunda sobre aspectos culturais, históricos e sociais da Tunísia.','📖'],
            ['card','Cultura','Exploração da rica herança cultural, gastronomia e tradições tunisianas.','💡'],
            ['card','Tecnologia','Análise do desenvolvimento tecnológico e inovação no país mediterrâneo.','💻'],
            ['card','Empreendedorismo','Desenvolvimento de ideias de negócios inspiradas na economia tunisiana.','📈'],
            ['objective','Desenvolver pesquisa acadêmica sobre a Tunísia','Desenvolver pesquisa acadêmica sobre a Tunísia',''],
            ['objective','Promover o conhecimento intercultural','Promover o conhecimento intercultural',''],
            ['objective','Estimular o pensamento crítico e criativo','Estimular o pensamento crítico e criativo',''],
            ['objective','Integrar conhecimentos de diversas disciplinas','Integrar conhecimentos de diversas disciplinas',''],
            ['competency','Trabalho em equipe e colaboração','Trabalho em equipe e colaboração',''],
            ['competency','Comunicação oral e escrita','Comunicação oral e escrita',''],
            ['competency','Organização e gestão de projetos','Organização e gestão de projetos',''],
            ['competency','Criatividade e inovação','Criatividade e inovação',''],
        ],
        'mapa' => [
            ['map_marker','Túnis','Medina de Túnis e Ruínas de Cartago','🌍','Capital',null,'Mistura história milenar com modernidade cosmopolita.','28%,55%'],
            ['map_marker','Sousse','Medina de Sousse e Ribat','🌍','Sahel',null,"Conhecida como a Pérola do Sahel pelas praias lindas.",'40%,57%'],
            ['map_marker','Monastir','Ribat de Monastir e Mausoléu','🌍','Sahel',null,'Cidade litorânea com uma das fortalezas mais bem preservadas.','44%,59%'],
            ['map_marker','Sfax','Medina Muralhada e Mercados Tradicionais','🌍','Sfax',null,'É o principal centro econômico e industrial do país.','52%,55%'],
            ['map_marker','Hammamet','Praias e Villa Sebastian','🌍','Nabeul',null,'Destino favorito para resorts e águas cristalinas.','38%,60%'],
        ],
        'cultura' => [
            ['cultural_card','Gastronomia','Degustação de pratos típicos tunisianos','🍴','Cuscuz, tajine, brik e doces tradicionais','assets/img/img01.png'],
            ['cultural_card','Música','Apresentação de músicas e danças tradicionais','🎵','Ritmos mediterrâneos e instrumentos árabes','assets/img/img02.png'],
            ['cultural_card','História','Exposição sobre a rica história tunisiana','📖','De Cartago aos dias atuais','assets/img/img03.png'],
        ],
        'cronograma' => [
            ['timeline','Escolha do País','Definição da Tunísia como tema principal do projeto.','','Março 2026'],
            ['timeline','Pesquisa Inicial','Levantamento de dados históricos, culturais e econômicos.','','Março - Abril'],
            ['timeline','Coleta de Informações','Pesquisa aprofundada em diversas áreas do conhecimento.','','Abril - Maio'],
            ['timeline','Produção dos Materiais','Criação de banners, apresentações, maquetes e conteúdos digitais.','','Maio - Junho'],
            ['timeline','Preparação das Apresentações','Organização dos eventos e ensaios finais.','','Junho 2026'],
            ['timeline','Evento Final','Semana Cultural, Feira do Conhecimento e Feira de Empreendedorismo.','','Junho 2026'],
        ],
        'conhecimento' => [
            ['knowledge','Geografia','Localização estratégica no Mediterrâneo; Deserto do Saara e regiões costeiras; Clima mediterrâneo predominante','🌍'],
            ['knowledge','Economia','Turismo como setor estratégico; Produção de azeite e tâmaras; Indústria têxtil e tecnológica','💰'],
            ['knowledge','Educação','Sistema público e gratuito; Alto índice de alfabetização; Universidades reconhecidas','🎓'],
            ['knowledge','Tecnologia','Hub tecnológico do Norte da África; Crescimento de startups; Infraestrutura digital moderna','💻'],
            ['knowledge','Turismo','Patrimônios da UNESCO; Praias do Mediterrâneo; Sítios arqueológicos de Cartago','🏛️'],
            ['knowledge','Sustentabilidade','Expansão da energia solar; Conservação ambiental; Agricultura sustentável','🌱'],
            ['stat','6','Áreas Pesquisadas',''],
            ['stat','20+','Fontes Consultadas',''],
            ['stat','100%','Conteúdo Autoral',''],
            ['stat','2026','Projeto Integrador',''],
        ],
        'empreendedorismo' => [
            ['business','Bambalouni','Doce tradicional tunisiano preparado com massa fermentada, frita até atingir textura crocante por fora e macia por dentro, finalizada com açúcar cristal.','🍩',null,'assets/img/bambalouni.png'],
            ['feature','Receita Tradicional','Preparada seguindo técnicas centenárias tunisianas.','🍩'],
            ['feature','Sabor Autêntico','Experiência gastronômica única e memorável.','⭐'],
            ['feature','Origem Cultural','Inspirado nas ruas históricas de Sidi Bou Said.','📍'],
            ['feature','Potencial de Mercado','Produto diferenciado para eventos e cafeterias.','📈'],
            ['model','Produto','Bambalouni tradicional; Versões gourmet; Kits para preparo',''],
            ['model','Público-Alvo','Turistas; Amantes da gastronomia; Eventos culturais',''],
            ['model','Diferenciais','Receita original tunisiana; Ingredientes selecionados; Experiência cultural',''],
            ['model','Estratégia','Parcerias com cafeterias; Feiras gastronômicas; Marketing digital',''],
        ],
    ];
    return $data[$identifier] ?? [];
}

function ensureSectionItems(PDO $pdo, int $sectionId, string $identifier): array {
    $stmt = $pdo->prepare('SELECT * FROM section_items WHERE section_id = ? ORDER BY display_order ASC, id ASC');
    $stmt->execute([$sectionId]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $sectionStmt = $pdo->prepare('SELECT custom_data FROM sections WHERE id = ?');
    $sectionStmt->execute([$sectionId]);
    $customRaw = $sectionStmt->fetchColumn();
    $customData = is_string($customRaw) ? json_decode($customRaw, true) : [];
    if (!is_array($customData)) $customData = [];
    if (!empty($customData['_section_items_migrated'])) return $items;

    $defaults = defaultSectionItems($identifier);
    if ($defaults) {
        // Migra os textos que antes estavam fixos no HTML apenas uma vez.
        $existingKeys = [];
        foreach ($items as $existing) $existingKeys[$existing['item_type'] . '|' . $existing['title']] = true;
        $missing = array_values(array_filter($defaults, fn($row) => !isset($existingKeys[$row[0] . '|' . $row[1]])));
        if ($missing) {
            $insert = $pdo->prepare('INSERT INTO section_items (section_id,item_type,title,description,icon,subtitle,image_path,badge,link_url,display_order,status,is_visible) VALUES (?,?,?,?,?,?,?,?,?,?,\'published\',1)');
            $order = count($items) + 1;
            foreach ($missing as $row) {
                $type = $row[0]; $title = $row[1] ?? ''; $description = $row[2] ?? ''; $icon = $row[3] ?? '';
                $subtitle = $row[4] ?? null; $image = $row[5] ?? null; $badge = $row[6] ?? null; $link = $row[7] ?? null;
                $insert->execute([$sectionId,$type,$title,$description,$icon,$subtitle,$image,$badge,$link,$order++]);
            }
        }
    }
    $customData['_section_items_migrated'] = true;
    $update = $pdo->prepare('UPDATE sections SET custom_data = ?, updated_at = updated_at WHERE id = ?');
    $update->execute([json_encode($customData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $sectionId]);
    $stmt->execute([$sectionId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}