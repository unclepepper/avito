<?php

declare(strict_types=1);

namespace BaksDev\Avito\Repository\AllAvitoToken;

use BaksDev\Auth\Email\Entity\Account;
use BaksDev\Auth\Email\Entity\Event\AccountEvent;
use BaksDev\Auth\Email\Entity\Status\AccountStatus;
use BaksDev\Avito\Entity\AvitoToken;
use BaksDev\Avito\Entity\Event\AvitoTokenEvent;
use BaksDev\Core\Doctrine\DBALQueryBuilder;
use BaksDev\Core\Form\Search\SearchDTO;
use BaksDev\Core\Services\Paginator\PaginatorInterface;
use BaksDev\Users\Profile\UserProfile\Entity\Avatar\UserProfileAvatar;
use BaksDev\Users\Profile\UserProfile\Entity\Event\UserProfileEvent;
use BaksDev\Users\Profile\UserProfile\Entity\Info\UserProfileInfo;
use BaksDev\Users\Profile\UserProfile\Entity\Personal\UserProfilePersonal;
use BaksDev\Users\Profile\UserProfile\Entity\UserProfile;
use BaksDev\Users\Profile\UserProfile\Type\Id\UserProfileUid;

final class AllAvitoTokenRepository implements AllAvitoTokenInterface
{
    private ?SearchDTO $search = null;

    private ?UserProfileUid $profile = null;

    public function __construct(
        private readonly DBALQueryBuilder $DBALQueryBuilder,
        private readonly PaginatorInterface $paginator,
    ) {}

    public function search(SearchDTO $search): self
    {
        $this->search = $search;

        return $this;
    }

    public function profile(UserProfileUid|string $profile): self
    {
        if(is_string($profile))
        {
            $profile = new UserProfileUid($profile);
        }

        $this->profile = $profile;

        return $this;
    }

    public function findAll(): PaginatorInterface
    {
        $qb = $this->DBALQueryBuilder
            ->createQueryBuilder(self::class)
            ->bindLocal();

        $qb->select('avito_token.id');
        $qb->addSelect('avito_token.event');
        $qb->from(AvitoToken::class, 'avito_token');

        /** Eсли не админ - только токен профиля */
        if($this->profile)
        {
            $qb->where('avito_token.id = :profile')
                ->setParameter('profile', $this->profile, UserProfileUid::TYPE);
        }

        $qb->addSelect('event.active');
        $qb->join(
            'avito_token',
            AvitoTokenEvent::class,
            'event',
            'event.id = avito_token.event AND event.profile = avito_token.id'
        );

        // ОТВЕТСТВЕННЫЙ

        // UserProfile
        $qb
            ->addSelect('users_profile.event as users_profile_event')
            ->leftJoin(
                'avito_token',
                UserProfile::class,
                'users_profile',
                'users_profile.id = avito_token.id'
            );


        // Info
        $qb
            ->addSelect('users_profile_info.status as users_profile_status')
            ->leftJoin(
                'avito_token',
                UserProfileInfo::class,
                'users_profile_info',
                'users_profile_info.profile = avito_token.id'
            );

        // Event
        $qb->leftJoin(
            'users_profile',
            UserProfileEvent::class,
            'users_profile_event',
            'users_profile_event.id = users_profile.event'
        );


        // Personal
        $qb->addSelect('users_profile_personal.username AS users_profile_username');

        $qb->leftJoin(
            'users_profile_event',
            UserProfilePersonal::class,
            'users_profile_personal',
            'users_profile_personal.event = users_profile_event.id'
        );

        // Avatar

        $qb->addSelect("CASE WHEN users_profile_avatar.name IS NOT NULL THEN CONCAT ( '/upload/".$qb->table(UserProfileAvatar::class)."' , '/', users_profile_avatar.name) ELSE NULL END AS users_profile_avatar");
        $qb->addSelect("users_profile_avatar.ext AS users_profile_avatar_ext");
        $qb->addSelect('users_profile_avatar.cdn AS users_profile_avatar_cdn');

        $qb->leftJoin(
            'users_profile_event',
            UserProfileAvatar::class,
            'users_profile_avatar',
            'users_profile_avatar.event = users_profile_event.id'
        );

        /** ACCOUNT */

        $qb->leftJoin(
            'users_profile_info',
            Account::class,
            'account',
            'account.id = users_profile_info.usr'
        );

        $qb->addSelect('account_event.email AS account_email');
        $qb->leftJoin(
            'account',
            AccountEvent::class,
            'account_event',
            'account_event.id = account.event AND account_event.account = account.id'
        );

        $qb->addSelect('account_status.status as account_status');
        $qb->leftJoin(
            'account_event',
            AccountStatus::class,
            'account_status',
            'account_status.event = account_event.id'
        );

        /* Поиск */
        if($this->search?->getQuery())
        {
            $qb
                ->createSearchQueryBuilder($this->search)
                ->addSearchEqualUid('avito_token.id')
                ->addSearchEqualUid('avito_token.event')
                ->addSearchLike('account_event.email')
                ->addSearchLike('users_profile_personal.username');
        }

        return $this->paginator->fetchAllAssociative($qb);

    }
}