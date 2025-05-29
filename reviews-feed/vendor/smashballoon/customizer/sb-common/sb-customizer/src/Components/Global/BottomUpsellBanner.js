import { __ } from "@wordpress/i18n";
import { useContext, useState } from "react";
import SbUtils from "../../Utils/SbUtils";
import Button from "../Common/Button";

const BottomUpsellBanner = ( props ) => {
    const currentContext = SbUtils.getCurrentContext();
    const {
        sbCustomizer,
        isPro
    } = useContext( currentContext );

    const [ contentActive, setContentActive ] = useState( false );
    const [ licenseKey, setLicenseKey ] = useState( sbCustomizer?.pluginSettings?.license_key || '' );

    const proBannerOutput = () => {
        return (
            <>
                <div className='sb-bottom-banner-pro-top sb-fs'>
                    <div className='sb-bottom-banner-pro-img'>
                        <img src={ window.sb_customizer.assetsURL + 'sb-customizer/assets/images/access-bundle.svg'} alt={ __('All Access Bundle', 'sb-customizer') } />
                    </div>
                    <div className='sb-bottom-banner-pro-content'>
                        <h3 className='sb-dark-text sb-h3'>
                           { __('Upgrade to the', 'sb-customizer') }<span>{ __(' All Access Bundle', 'sb-customizer') }</span>{ __(' to get all of our Pro Plugins', 'sb-customizer') }
                        </h3>
                        <p className='sb-text-small sb-dark2-text '>
                            { __('Includes all Smash Balloon plugins for one low price: Instagram, Facebook, Twitter, YouTube and Social Wall', 'sb-customizer') }
                        </p>
                    </div>
                    <Button
                        type='secondary'
                        size='medium'
                        text={ __('Learn More', 'sb-customizer') }
                        icon='chevron-right'
                        iconSize='8'
                        boxshadow={false}
                        icon-position='right'
                        link={'https://smashballoon.com/all-access/?edd_license_key='+licenseKey+'&upgrade=true&utm_campaign=reviews-pro&utm_source=all-feeds&utm_medium=footer-banner&utm_content=learn-more'}
                        target='_blank'
                    />

                </div>
                {
                    !isPro &&
                    <div className='sb-bottom-banner-pro-footer sb-text-small sb-dark-text sb-fs'>
                        <span>{ __('BONUS', 'sb-customizer') }</span>
                        { __('Lite users get', 'sb-customizer') } <strong>{ __('50% Off', 'sb-customizer') }</strong> { __('automatically applied at checkout', 'sb-customizer') }
                    </div>
                }
            </>
        )
    }

    const freeBannerOutput = () => {

        const featuresList = [
            {
                icon : 'fb-rev-feature.png',
                heading : __('Display Reviews<br/>from Facebook', 'sb-customizer')
            },
            {
                icon : 'tpad-rev-feature.png',
                heading : __('Display Reviews<br/>from TripAdvisor', 'sb-customizer')
            },
            {
                icon : 'moderate-feature.png',
                heading : __('Moderate your<br/>Review Feeds', 'sb-customizer')
            },
            {
                icon : 'vid-im-feature.png',
                heading : __('Display Images<br/>and Videos', 'sb-customizer')
            }
        ];
        const muchMoreList = [
            __('One-click templates', 'sb-customizer'),
            __('Advanced filtering', 'sb-customizer'),
            __('Carousel layout', 'sb-customizer'),
            __('Mobile design support', 'sb-customizer'),
            __('Custom icons', 'sb-customizer'),
            __('Pro support', 'sb-customizer')
        ];

        const utmSource = isPro ? 'reviews-pro' : 'reviews-free'

        return (
            <>
                <div className='sb-bottom-banner-top sb-bottom-banner-section sb-fs'>
                    <div className='sb-bottom-banner-top-icon'>
                        { SbUtils.printIcon('reviews-small-icon') }
                    </div>
                    <div className='sb-bottom-banner-top-content'>
                        <h3 className='sb-h3 sb-fs'>{ __('Get more features with Reviews Feed Pro', 'sb-customizer') }</h3>
                        <span className='sb-text-small sb-dark2-text sb-fs'>{ __('Get reviews from more platforms, review moderation, carousel layouts, and much more!', 'sb-customizer') }</span>
                        <div className='sb-bottom-banner-action-btns sb-fs'>
                            <Button
                                customClass='sb-upgrade-lite-banner-btn'
                                size='medium'
                                text={ __('Lite Plugin Users get 50% OFF (auto-applied at checkout)', 'sb-customizer') }
                                icon='tag'
                                boxshadow={false}
                                link={'https://smashballoon.com/pricing/reviews-feed/?utm_campaign='+utmSource+'&utm_source=all-feeds&utm_medium=lite-upgrade-footer-coupon&utm_content=LiteUsers50OFF'}
                                target='_blank'
                            />
                            <Button
                                size='medium'
                                type='brand'
                                text={ __('Upgrade to Pro', 'sb-customizer') }
                                icon='diagonal-arrow'
                                icon-position='right'
                                iconSize='12'
                                link={'https://smashballoon.com/pricing/reviews-feed/?utm_campaign='+utmSource+'&utm_source=all-feeds&utm_medium=lite-upgrade-footer-cta&utm_content=UpgradeToPro'}
                                target='_blank'
                            />
                        </div>
                    </div>
                </div>
                {
                    contentActive &&
                    <div className='sb-bottom-banner-content sb-bottom-banner-section sb-fs'>
                        <div className='sb-bottom-banner-features-list sb-fs'>
                            {
                                featuresList.map( ( featureItem, fKey ) => {
                                return (
                                        <div className='sb-bottom-banner-features-item' key={fKey}>
                                            <img src={ window.sb_customizer.assetsURL + 'sb-customizer/assets/images/' + featureItem.icon} alt={featureItem.heading} />
                                            <strong className='sb-text-small sb-dark-text'dangerouslySetInnerHTML={{__html: featureItem.heading }}></strong>
                                        </div>
                                )
                                }  )
                            }
                        </div>
                        <div className='sb-upsell-modal-content sb-fs'>
                            <strong>{ __('And much more!', 'sb-customizer') }</strong>
                            <div className='sb-upsell-modal-content-list sb-fs'>
                                {
                                    muchMoreList.map( (elm, key) => {
                                        return (
                                            <span className='sb-dark2-text sb-text-small ' key={key}>{ elm }</span>
                                        )
                                    } )
                                }
                            </div>
                        </div>
                    </div>
                }

                <div
                    className='sb-bottom-banner-toggler sb-text-small sb-bold sb-fs'
                    onClick={ () => {
                        setContentActive( !contentActive )
                    }}
                >
                    { ! contentActive ? __('Show Features', 'sb-customizer') : __('Hide Features', 'sb-customizer') }
                    { ! contentActive ?  SbUtils.printIcon( 'chevron-bottom' ) : SbUtils.printIcon( 'chevron-top' ) }
                </div>

            </>
        )
    }


    const linksFooter = [
        {
            text : __('Docs', 'sb-customizer'),
            link : isPro ? 'https://smashballoon.com/docs/reviews/?utm_campaign=reviews-pro&utm_source=settings-footer&utm_medium=docs-link&utm_content=Docs' : 'https://smashballoon.com/docs/reviews/?utm_campaign=reviews-free&utm_source=settings-footer&utm_medium=docs-link&utm_content=Docs'
        },
        {
            text : __('Plugins', 'sb-customizer'),
            target :'_self',
            link : sbCustomizer?.aboutPageUrl + '#sb-pluginlist-ctn'
        },
        {
            text : __('Support', 'sb-customizer'),
            link : isPro ? 'https://smashballoon.com/support/?utm_campaign=reviews-pro&utm_source=settings-footer&utm_medium=support-link&utm_content=Support' : 'https://wordpress.org/support/plugin/reviews-feed/'
        }
    ]

     const socialLinksFooter = [
        {
            icon : 'facebook',
            size: 22,
            link : 'https://facebook.com/smashballoon'
        },
        {
            icon : 'instagram',
            size: 32,
            link : 'https://instagram.com/smashballoon'
        },
        {
            icon : 'twitter',
            size: 18,
            link : 'https://twitter.com/smashballoon'
        },
        {
            icon : 'youtube',
            size: 22,
            link : 'https://www.youtube.com/channel/UC_dpcpRbZL_OBrGYHG7L5bA'
        }
     ]

    return (
        <>
            <div className='sb-bottom-banner-ctn sb-fs'>
                { isPro && props?.hidePro !== true ? proBannerOutput() : freeBannerOutput() }
            </div>
            <div className='sb-bottom-footer-social-ctn sb-fs'>
                <strong className='sb-bottom-footer-description'>
                    {__('Made with', 'sb-customizer')}
                    <svg width="16px" height="16px" viewBox="0 0 24 24"><path d="M14 20.408c-.492.308-.903.546-1.192.709-.153.086-.308.17-.463.252h-.002a.75.75 0 01-.686 0 16.709 16.709 0 01-.465-.252 31.147 31.147 0 01-4.803-3.34C3.8 15.572 1 12.331 1 8.513 1 5.052 3.829 2.5 6.736 2.5 9.03 2.5 10.881 3.726 12 5.605 13.12 3.726 14.97 2.5 17.264 2.5 20.17 2.5 23 5.052 23 8.514c0 3.818-2.801 7.06-5.389 9.262A31.146 31.146 0 0114 20.408z"/></svg>
                    {__('by the Smash Balloon Team', 'sb-customizer')}
                </strong>
                <div className='sb-bottom-footer-links'>
                    {
                        linksFooter.map( (link, key) => {
                            return(
                                <a href={link.link} key={key} target={link?.target ? link.target : '_blank'} rel='noreferrer'>{link.text}</a>
                            )

                        })
                    }
                </div>
                <div className='sb-bottom-footer-sociallinks'>
                    {
                        socialLinksFooter.map( (socialLink, key) => {
                            return(
                                <a href={socialLink.link} key={key} target={socialLink?.target ? socialLink.target : '_blank'} rel='noreferrer'>
                                    { SbUtils.printIcon( socialLink.icon, false, false, socialLink?.size )}
                                </a>
                            )

                        })
                    }
                </div>
            </div>
        </>
    )
}

export default BottomUpsellBanner;