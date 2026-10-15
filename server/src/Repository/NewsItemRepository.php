<?php

namespace App\Repository;

use App\Entity\NewsItem;
use App\Entity\NewsSource;
use App\Entity\NewsStoryline;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<NewsItem>
 */
class NewsItemRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, NewsItem::class);
    }

    /**
     * @param list<string> $keys
     *
     * @return array<string, NewsItem> уже сохранённые новости издания по ключу
     */
    public function findByKeys(NewsSource $source, array $keys): array
    {
        if ($keys === []) {
            return [];
        }
        $items = $this->createQueryBuilder('n')
            ->where('n.source = :source AND n.externalKey IN (:keys)')
            ->setParameter('source', $source)
            ->setParameter('keys', $keys)
            ->getQuery()
            ->getResult();

        $byKey = [];
        foreach ($items as $item) {
            $byKey[$item->getExternalKey()] = $item;
        }

        return $byKey;
    }

    /**
     * Новости, у которых ещё нет полного текста, свежие первыми: текст не скачан со страницы,
     * лента дала только анонс (короче [NewsArticleFetcher::FULL_TEXT]) и попытки не исчерпаны.
     *
     * @return list<NewsItem>
     */
    public function findNeedingContent(int $limit, int $fullText, int $maxAttempts): array
    {
        return $this->createQueryBuilder('n')
            ->join('n.source', 's')
            ->where('s.format <> :digest AND n.contentFetchedAt IS NULL AND n.contentAttempts < :attempts')
            ->andWhere('n.content IS NULL OR LENGTH(n.content) < :fullText')
            ->setParameter('digest', NewsSource::FORMAT_DIGEST)
            ->setParameter('attempts', $maxAttempts)
            ->setParameter('fullText', $fullText)
            ->orderBy('n.sortAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Новости изданий не на русском без перевода заголовка — свежие первыми, без сводок: лента и сюжеты
     * нужны для актуальных новостей, архив догоняется следующими проходами.
     *
     * @return list<NewsItem>
     */
    public function findNeedingHeadlineTranslation(int $limit, int $maxAttempts): array
    {
        return $this->createQueryBuilder('n')
            ->join('n.source', 's')
            ->addSelect('s')
            ->where('s.format <> :digest AND s.language <> :ru AND n.translatedTitle IS NULL AND n.headlineTranslationAttempts < :attempts')
            ->setParameter('digest', NewsSource::FORMAT_DIGEST)
            ->setParameter('ru', NewsSource::LANGUAGE_RU)
            ->setParameter('attempts', $maxAttempts)
            ->orderBy('n.sortAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Новости изданий не на русском с полным текстом, но без его перевода, — свежие первыми.
     * Полный текст — то же правило, что `fullText` в API: скачан со страницы или из ленты не короче $fullText.
     *
     * @return list<NewsItem>
     */
    public function findNeedingContentTranslation(int $limit, int $fullText, int $maxAttempts): array
    {
        return $this->createQueryBuilder('n')
            ->join('n.source', 's')
            ->addSelect('s')
            ->where('s.format <> :digest AND s.language <> :ru AND n.translatedContent IS NULL AND n.contentTranslationAttempts < :attempts')
            ->andWhere('n.contentFetchedAt IS NOT NULL OR LENGTH(n.content) >= :fullText')
            ->setParameter('digest', NewsSource::FORMAT_DIGEST)
            ->setParameter('ru', NewsSource::LANGUAGE_RU)
            ->setParameter('attempts', $maxAttempts)
            ->setParameter('fullText', $fullText)
            ->orderBy('n.sortAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Статьи, которые читатели попросили перевести, — в порядке просьб.
     *
     * @return list<NewsItem>
     */
    public function findRequestedContentTranslation(int $limit): array
    {
        return $this->createQueryBuilder('n')
            ->join('n.source', 's')
            ->addSelect('s')
            ->where('n.translationRequestedAt IS NOT NULL AND n.translatedContent IS NULL')
            ->orderBy('n.translationRequestedAt', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Новости без вектора — свежие первыми, без сводок: сюжетные линии нужны для актуальных новостей,
     * а архив досчитается следующими проходами.
     *
     * @return list<NewsItem>
     */
    public function findNeedingEmbedding(int $limit): array
    {
        return $this->createQueryBuilder('n')
            ->join('n.source', 's')
            ->where('n.embedding IS NULL AND s.format <> :digest')
            ->setParameter('digest', NewsSource::FORMAT_DIGEST)
            ->orderBy('n.sortAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Свежие новости (без сводок), которые модель ещё не проверяла на влияние на Мосбиржу, — свежие первыми.
     * Архив не догоняется: последствие интересно, пока новость свежая, а квота agy ограничена.
     *
     * @return list<NewsItem>
     */
    public function findNeedingMarketCheck(\DateTimeImmutable $since, int $limit): array
    {
        return $this->createQueryBuilder('n')
            ->join('n.source', 's')
            ->addSelect('s')
            ->where('n.marketCheckedAt IS NULL AND n.firstSeenAt >= :since AND s.format <> :digest')
            ->setParameter('since', $since)
            ->setParameter('digest', NewsSource::FORMAT_DIGEST)
            ->orderBy('n.sortAt', 'DESC')
            ->addOrderBy('n.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Новости с вектором, ещё не привязанные к сюжетной линии, — по порядку времени:
     * линия растёт так же, как шли события.
     *
     * @return list<NewsItem>
     */
    public function findUnassigned(int $limit): array
    {
        return $this->createQueryBuilder('n')
            ->join('n.source', 's')
            ->where('n.embedding IS NOT NULL AND n.storyline IS NULL AND s.format <> :digest')
            ->setParameter('digest', NewsSource::FORMAT_DIGEST)
            ->orderBy('n.sortAt', 'ASC')
            ->addOrderBy('n.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Новости сюжетной линии, свежие первыми.
     *
     * @return list<NewsItem>
     */
    public function findByStoryline(NewsStoryline $storyline, int $limit = 500): array
    {
        return $this->createQueryBuilder('n')
            ->join('n.source', 's')
            ->addSelect('s')
            ->where('n.storyline = :line')
            ->setParameter('line', $storyline)
            ->orderBy('n.sortAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Новости изданий (без сводок), которые сервер впервые увидел за период, — материал для сводки.
     * Время первого появления, а не публикации: так в сводку попадает и CNN, и новость,
     * опубликованная задним числом.
     *
     * @return list<NewsItem>
     */
    public function findSeenBetween(\DateTimeImmutable $start, \DateTimeImmutable $end, int $limit): array
    {
        return $this->createQueryBuilder('n')
            ->join('n.source', 's')
            ->addSelect('s')
            ->where('n.firstSeenAt >= :start AND n.firstSeenAt < :end AND s.format <> :digest')
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->setParameter('digest', NewsSource::FORMAT_DIGEST)
            ->orderBy('n.sortAt', 'ASC')
            ->addOrderBy('n.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Лента: свежие сверху. Курсор — время и id последней показанной новости: время у новостей
     * совпадает, поэтому одного времени мало, чтобы не потерять и не повторить новость на стыке страниц.
     *
     * @param list<NewsSource> $sources пусто — все издания, кроме сводки
     *
     * @return list<NewsItem>
     */
    public function findFeed(array $sources, int $limit, ?\DateTimeImmutable $beforeAt = null, ?int $beforeId = null): array
    {
        $qb = $this->createQueryBuilder('n')
            ->join('n.source', 's')
            ->addSelect('s')
            ->orderBy('n.sortAt', 'DESC')
            ->addOrderBy('n.id', 'DESC')
            ->setMaxResults($limit);
        if ($sources !== []) {
            $qb->andWhere('n.source IN (:sources)')->setParameter('sources', $sources);
        } else {
            // «Все» — новости изданий из вкладок; сводки пересказывают их же и живут на своей вкладке,
            // а издания «только для сводки» в виджете не показываются.
            $qb->andWhere('s.format <> :digest AND s.showInWidget = true')->setParameter('digest', NewsSource::FORMAT_DIGEST);
        }
        if ($beforeAt !== null && $beforeId !== null) {
            $qb->andWhere('n.sortAt < :at OR (n.sortAt = :at AND n.id < :id)')
                ->setParameter('at', $beforeAt)
                ->setParameter('id', $beforeId);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Новости с даты, в заголовке которых (или в его переводе) встречается одно из слов, — свежие первыми.
     * Слово ищется в трёх написаниях — «сбербанк», «Сбербанк», «СБЕРБАНК»: LIKE в PostgreSQL различает
     * регистр, а LOWER в SQLite не понимает кириллицу.
     *
     * @param list<string> $words в нижнем регистре
     *
     * @return list<NewsItem>
     */
    public function findMentioning(array $words, \DateTimeImmutable $since, int $limit): array
    {
        if ($words === []) {
            return [];
        }
        $qb = $this->createQueryBuilder('n')
            ->join('n.source', 's')
            ->addSelect('s')
            ->where('n.sortAt >= :since AND s.format <> :digest')
            ->setParameter('since', $since)
            ->setParameter('digest', NewsSource::FORMAT_DIGEST);
        $match = $qb->expr()->orX();
        $i = 0;
        foreach ($words as $word) {
            $variants = array_unique([$word, mb_strtoupper(mb_substr($word, 0, 1)).mb_substr($word, 1), mb_strtoupper($word)]);
            foreach ($variants as $variant) {
                $match->add("n.title LIKE :w$i");
                $match->add("n.translatedTitle LIKE :w$i");
                $qb->setParameter("w$i", '%'.addcslashes($variant, '%_').'%');
                ++$i;
            }
        }

        return $qb->andWhere($match)
            ->orderBy('n.sortAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
